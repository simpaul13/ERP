<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, ClipboardList, Plus, Send, Truck, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SupplierOption = { id: number; name: string };
type ItemOption = { id: number; name: string; sku: string; unitCost: number };
type WarehouseOption = {
    id: number;
    name: string;
    locations: { id: number; name: string }[];
};
type PurchaseOrderLine = {
    id: number;
    inventoryItemId: number | null;
    name: string;
    sku: string;
    quantity: number;
    receivedQuantity: number;
    returnedQuantity: number;
    unitCost: number;
    lineTotal: number;
};
type PurchaseOrderEvent = {
    id: number;
    fromStatus: string | null;
    toStatus: string;
    note: string | null;
    userName: string;
    createdAt: string | null;
};
type PurchaseOrder = {
    id: number;
    number: string;
    supplierName: string;
    status: string;
    statusLabel: string;
    expectedDate: string;
    reference: string | null;
    totalAmount: number;
    createdBy: string;
    createdById: number | null;
    cancellationReason: string | null;
    items: PurchaseOrderLine[];
    events: PurchaseOrderEvent[];
};
type DraftLine = {
    inventory_item_id: string;
    quantity: number;
    unit_cost: number;
};

const props = defineProps<{
    orders: PurchaseOrder[];
    suppliers: SupplierOption[];
    items: ItemOption[];
    warehouses: WarehouseOption[];
    canCreate: boolean;
    canApprove: boolean;
    canReceive: boolean;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Purchase orders',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/purchase-orders`
                    : '/purchase-orders',
            },
        ],
    }),
});

const page = usePage();
const createOpen = ref(false);
const receiveOpen = ref(false);
const returning = ref(false);
const saving = ref(false);
const formError = ref('');
const receivingError = ref('');
const selectedOrder = ref<PurchaseOrder | null>(null);
const selectedLine = ref<PurchaseOrderLine | null>(null);
const form = ref({
    supplier_id: '',
    expected_date: '',
    reference: '',
    items: [] as DraftLine[],
});
const receipt = ref({
    warehouse_id: '',
    warehouse_location_id: '',
    quantity: 1,
    reason: '',
});

const currency = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
});
const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
});
const selectedWarehouse = computed(() =>
    props.warehouses.find(
        (warehouse) => String(warehouse.id) === receipt.value.warehouse_id,
    ),
);
const draftTotal = computed(() =>
    form.value.items.reduce(
        (total, line) =>
            total + Number(line.quantity || 0) * Number(line.unit_cost || 0),
        0,
    ),
);

function teamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;
    return team?.slug ?? null;
}

function orderUrl(order: PurchaseOrder): string | null {
    const slug = teamSlug();
    return slug ? `/${slug}/purchase-orders/${order.id}` : null;
}

function openCreate(): void {
    form.value = {
        supplier_id: '',
        expected_date: new Date().toISOString().slice(0, 10),
        reference: '',
        items: [newLine()],
    };
    formError.value = '';
    createOpen.value = true;
}

function newLine(): DraftLine {
    const firstItem = props.items[0];
    return {
        inventory_item_id: firstItem ? String(firstItem.id) : '',
        quantity: 1,
        unit_cost: firstItem?.unitCost ?? 0,
    };
}

function addLine(): void {
    form.value.items.push(newLine());
}

function removeLine(index: number): void {
    if (form.value.items.length > 1) {
        form.value.items.splice(index, 1);
    }
}

function updateDraftItem(index: number): void {
    const selected = props.items.find(
        (item) =>
            String(item.id) === form.value.items[index]?.inventory_item_id,
    );
    if (selected && form.value.items[index]) {
        form.value.items[index].unit_cost = selected.unitCost;
    }
}

function createOrder(): void {
    const slug = teamSlug();
    if (!slug || saving.value) return;

    saving.value = true;
    formError.value = '';
    router.post(`/${slug}/purchase-orders`, form.value, {
        preserveScroll: true,
        onSuccess: () => (createOpen.value = false),
        onError: (errors) =>
            (formError.value =
                Object.values(errors)[0] ?? 'Could not create this order.'),
        onFinish: () => (saving.value = false),
    });
}

function postAction(order: PurchaseOrder, action: 'submit' | 'approve'): void {
    const url = orderUrl(order);
    if (url) router.post(`${url}/${action}`, {}, { preserveScroll: true });
}

function cancelOrder(order: PurchaseOrder): void {
    const url = orderUrl(order);
    if (!url) return;
    const reason = window.prompt(`Why are you cancelling ${order.number}?`);
    if (reason?.trim()) {
        router.post(`${url}/cancel`, { reason }, { preserveScroll: true });
    }
}

function openReceive(order: PurchaseOrder, line: PurchaseOrderLine): void {
    returning.value = false;
    selectedOrder.value = order;
    selectedLine.value = line;
    receipt.value = {
        warehouse_id: props.warehouses[0] ? String(props.warehouses[0].id) : '',
        warehouse_location_id: props.warehouses[0]?.locations[0]
            ? String(props.warehouses[0].locations[0].id)
            : '',
        quantity: Math.max(1, line.quantity - line.receivedQuantity),
        reason: '',
    };
    receivingError.value = '';
    receiveOpen.value = true;
}

function openReturn(order: PurchaseOrder, line: PurchaseOrderLine): void {
    openReceive(order, line);
    returning.value = true;
    receipt.value.quantity = Math.max(
        1,
        line.receivedQuantity - line.returnedQuantity,
    );
}

function changeReceiptWarehouse(): void {
    receipt.value.warehouse_location_id = selectedWarehouse.value?.locations[0]
        ? String(selectedWarehouse.value.locations[0].id)
        : '';
}

function receiveOrderLine(): void {
    const order = selectedOrder.value;
    const line = selectedLine.value;
    const url = order ? orderUrl(order) : null;
    if (!url || !line || saving.value) return;

    saving.value = true;
    receivingError.value = '';
    router.post(
        `${url}/${returning.value ? 'return' : 'receive'}`,
        {
            purchase_order_item_id: line.id,
            ...receipt.value,
            ...(returning.value ? { reason: receipt.value.reason } : {}),
        },
        {
            preserveScroll: true,
            onSuccess: () => (receiveOpen.value = false),
            onError: (errors) =>
                (receivingError.value =
                    Object.values(errors)[0] ??
                    'Could not record this receipt.'),
            onFinish: () => (saving.value = false),
        },
    );
}

function canSubmit(order: PurchaseOrder): boolean {
    const user = page.props.auth as { user?: { id: number } } | undefined;
    return (
        order.status === 'draft' &&
        (props.canApprove || order.createdById === user?.user?.id)
    );
}

function canCancel(order: PurchaseOrder): boolean {
    const user = page.props.auth as { user?: { id: number } } | undefined;
    return (
        ['draft', 'submitted', 'approved', 'partially_received'].includes(
            order.status,
        ) &&
        (props.canApprove ||
            (order.status === 'draft' && order.createdById === user?.user?.id))
    );
}

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

function formatDateTime(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '';
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'received') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    if (status === 'submitted') return 'outline';
    return 'default';
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Purchase orders" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Purchase orders
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create supplier orders, route them for approval, and receive
                    deliveries into stock.
                </p>
                <p
                    v-if="!canApprove"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Purchase order approval and stock receiving are limited to
                    team admins and owners.
                </p>
            </div>
            <Button
                v-if="canCreate"
                size="sm"
                :disabled="!suppliers.length || !items.length"
                @click="openCreate"
            >
                <Plus class="size-4" /> New purchase order
            </Button>
        </header>

        <section v-if="orders.length" class="grid gap-4">
            <article
                v-for="order in orders"
                :key="order.id"
                class="rounded-lg border bg-card p-4 sm:p-5"
            >
                <div
                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold">
                                {{ order.number }}
                            </h2>
                            <Badge :variant="statusVariant(order.status)">{{
                                order.statusLabel
                            }}</Badge>
                        </div>
                        <p class="mt-1 font-medium">{{ order.supplierName }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Expected {{ formatDate(order.expectedDate)
                            }}<span v-if="order.reference">
                                · Ref {{ order.reference }}</span
                            >
                            · Created by {{ order.createdBy }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-2 font-semibold">{{
                            currency.format(order.totalAmount)
                        }}</span>
                        <Button
                            v-if="canSubmit(order)"
                            size="sm"
                            @click="postAction(order, 'submit')"
                        >
                            <Send class="size-4" /> Submit
                        </Button>
                        <Button
                            v-if="canApprove && order.status === 'submitted'"
                            size="sm"
                            @click="postAction(order, 'approve')"
                        >
                            <Check class="size-4" /> Approve
                        </Button>
                        <Button
                            v-if="canCancel(order)"
                            variant="outline"
                            size="sm"
                            @click="cancelOrder(order)"
                        >
                            <X class="size-4" /> Cancel
                        </Button>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto rounded-md border">
                    <table class="w-full min-w-[520px] text-left text-sm">
                        <thead
                            class="bg-muted/50 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Item</th>
                                <th class="px-3 py-2 font-medium">Ordered</th>
                                <th class="px-3 py-2 font-medium">Received</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Unit cost
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Line total
                                </th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="line in order.items" :key="line.id">
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{
                                        line.name
                                    }}</span
                                    ><span
                                        class="ml-2 text-xs text-muted-foreground"
                                        >{{ line.sku }}</span
                                    >
                                </td>
                                <td class="px-3 py-2">{{ line.quantity }}</td>
                                <td class="px-3 py-2">
                                    {{ line.receivedQuantity }} /
                                    {{ line.quantity }}
                                    <span v-if="line.returnedQuantity">
                                        ·
                                        {{ line.returnedQuantity }}
                                        returned</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ currency.format(line.unitCost) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ currency.format(line.lineTotal) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Button
                                        v-if="
                                            canReceive &&
                                            [
                                                'approved',
                                                'partially_received',
                                            ].includes(order.status) &&
                                            line.receivedQuantity <
                                                line.quantity
                                        "
                                        variant="outline"
                                        size="sm"
                                        @click="openReceive(order, line)"
                                    >
                                        <Truck class="size-4" /> Receive
                                    </Button>
                                    <Button
                                        v-if="
                                            canReceive &&
                                            line.receivedQuantity >
                                                line.returnedQuantity &&
                                            [
                                                'partially_received',
                                                'received',
                                                'cancelled',
                                            ].includes(order.status)
                                        "
                                        variant="outline"
                                        size="sm"
                                        @click="openReturn(order, line)"
                                    >
                                        Return
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p
                    v-if="order.cancellationReason"
                    class="mt-3 text-sm text-destructive"
                >
                    Cancellation reason: {{ order.cancellationReason }}
                </p>
                <details class="mt-4 border-t pt-3">
                    <summary class="cursor-pointer text-sm font-medium">
                        Order history ({{ order.events.length }})
                    </summary>
                    <ol class="mt-3 grid gap-3 border-l pl-4">
                        <li
                            v-for="event in order.events"
                            :key="event.id"
                            class="relative text-sm"
                        >
                            <span
                                class="absolute top-1 -left-[21px] size-2 rounded-full bg-primary"
                            />
                            <p>{{ event.note }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ event.userName
                                }}<span v-if="event.createdAt">
                                    ·
                                    {{ formatDateTime(event.createdAt) }}</span
                                >
                            </p>
                        </li>
                    </ol>
                </details>
            </article>
        </section>
        <section
            v-else
            class="rounded-lg border border-dashed px-6 py-12 text-center"
        >
            <ClipboardList class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 font-medium">No purchase orders yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Create a draft to start purchasing from your suppliers.
            </p>
            <p
                v-if="!suppliers.length || !items.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                Add an active supplier and inventory item before creating an
                order.
            </p>
            <Button
                v-if="canCreate && suppliers.length && items.length"
                class="mt-4"
                @click="openCreate"
                ><Plus class="size-4" /> New purchase order</Button
            >
        </section>

        <Dialog v-model:open="createOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form class="grid gap-5" @submit.prevent="createOrder">
                    <DialogHeader class="text-left">
                        <DialogTitle>New purchase order</DialogTitle>
                        <DialogDescription
                            >Create a draft with supplier, expected delivery,
                            reference, and inventory lines.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="po-supplier">Supplier</Label
                            ><select
                                id="po-supplier"
                                v-model="form.supplier_id"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Select supplier
                                </option>
                                <option
                                    v-for="supplier in suppliers"
                                    :key="supplier.id"
                                    :value="String(supplier.id)"
                                >
                                    {{ supplier.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="po-date">Expected date</Label
                            ><Input
                                id="po-date"
                                v-model="form.expected_date"
                                type="date"
                                required
                            />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="po-reference">Reference</Label
                            ><Input
                                id="po-reference"
                                v-model="form.reference"
                                maxlength="255"
                                placeholder="Supplier quote or reference number"
                            />
                        </div>
                    </div>
                    <section class="grid gap-3">
                        <div class="flex items-center justify-between">
                            <h3 class="font-medium">Order items</h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addLine"
                                ><Plus class="size-4" /> Add item</Button
                            >
                        </div>
                        <div
                            v-for="(line, index) in form.items"
                            :key="index"
                            class="grid min-w-0 gap-3 rounded-md border p-3 sm:grid-cols-[minmax(0,1fr)_100px_130px_auto] sm:items-end"
                        >
                            <div class="grid min-w-0 gap-2">
                                <Label :for="`po-item-${index}`"
                                    >Inventory item</Label
                                ><select
                                    :id="`po-item-${index}`"
                                    v-model="line.inventory_item_id"
                                    required
                                    class="h-9 w-full max-w-full min-w-0 rounded-md border bg-background px-3 text-sm"
                                    @change="updateDraftItem(index)"
                                >
                                    <option value="" disabled>
                                        Select item
                                    </option>
                                    <option
                                        v-for="item in items"
                                        :key="item.id"
                                        :value="String(item.id)"
                                    >
                                        {{ item.name }} · {{ item.sku }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`po-quantity-${index}`"
                                    >Quantity</Label
                                ><Input
                                    :id="`po-quantity-${index}`"
                                    v-model.number="line.quantity"
                                    type="number"
                                    min="1"
                                    max="1000000"
                                    required
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`po-cost-${index}`"
                                    >Unit cost</Label
                                ><Input
                                    :id="`po-cost-${index}`"
                                    v-model.number="line.unit_cost"
                                    type="number"
                                    min="0"
                                    max="999999.99"
                                    step="0.01"
                                    required
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :disabled="form.items.length === 1"
                                :aria-label="`Remove item ${index + 1}`"
                                @click="removeLine(index)"
                                ><X class="size-4"
                            /></Button>
                        </div>
                        <p class="text-right text-sm font-semibold">
                            Order total: {{ currency.format(draftTotal) }}
                        </p>
                    </section>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formError }}
                    </p>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="createOpen = false"
                            >Cancel</Button
                        ><Button type="submit" :disabled="saving">{{
                            saving ? 'Saving…' : 'Create draft'
                        }}</Button></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="receiveOpen">
            <DialogContent class="sm:max-w-lg">
                <form class="grid gap-5" @submit.prevent="receiveOrderLine">
                    <DialogHeader class="text-left"
                        ><DialogTitle
                            >{{ returning ? 'Return' : 'Receive' }}
                            {{ selectedLine?.name }}</DialogTitle
                        ><DialogDescription
                            >{{
                                returning
                                    ? 'Record a supplier return against'
                                    : 'Record received stock against'
                            }}
                            {{ selectedOrder?.number }}. Remaining:
                            {{
                                returning
                                    ? (selectedLine?.receivedQuantity ?? 0) -
                                      (selectedLine?.returnedQuantity ?? 0)
                                    : (selectedLine?.quantity ?? 0) -
                                      (selectedLine?.receivedQuantity ?? 0)
                            }}.</DialogDescription
                        ></DialogHeader
                    >
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="po-receive-warehouse">Warehouse</Label
                            ><select
                                id="po-receive-warehouse"
                                v-model="receipt.warehouse_id"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                                @change="changeReceiptWarehouse"
                            >
                                <option
                                    v-for="warehouse in warehouses"
                                    :key="warehouse.id"
                                    :value="String(warehouse.id)"
                                >
                                    {{ warehouse.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="po-receive-location">Bin</Label
                            ><select
                                id="po-receive-location"
                                v-model="receipt.warehouse_location_id"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="location in selectedWarehouse?.locations ??
                                    []"
                                    :key="location.id"
                                    :value="String(location.id)"
                                >
                                    {{ location.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="po-receive-quantity">{{
                                returning
                                    ? 'Quantity returned'
                                    : 'Quantity received'
                            }}</Label
                            ><Input
                                id="po-receive-quantity"
                                v-model.number="receipt.quantity"
                                type="number"
                                min="1"
                                :max="
                                    returning
                                        ? (selectedLine?.receivedQuantity ??
                                              1) -
                                          (selectedLine?.returnedQuantity ?? 0)
                                        : (selectedLine?.quantity ?? 1) -
                                          (selectedLine?.receivedQuantity ?? 0)
                                "
                                required
                            />
                        </div>
                        <div v-if="returning" class="grid gap-2">
                            <Label for="po-return-reason">Return reason</Label>
                            <Input
                                id="po-return-reason"
                                v-model="receipt.reason"
                                required
                                maxlength="2000"
                            />
                        </div>
                    </div>
                    <p
                        v-if="receivingError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ receivingError }}
                    </p>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="receiveOpen = false"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :disabled="saving || !warehouses.length"
                            >{{
                                saving
                                    ? 'Recording…'
                                    : returning
                                      ? 'Record return'
                                      : 'Record receipt'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
