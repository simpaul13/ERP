<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ClipboardList,
    Pencil,
    Plus,
    Search,
    Send,
    Truck,
    Undo2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    index as purchaseOrderIndex,
    store as storePurchaseOrder,
    update as updatePurchaseOrder,
    submit as submitPurchaseOrder,
    approve as approvePurchaseOrder,
    cancel as cancelPurchaseOrder,
    requestRevision as requestPurchaseOrderRevision,
    receive as receivePurchaseOrder,
    returnGoods as returnPurchaseOrderGoods,
} from '@/actions/App/Http/Controllers/Purchases/PurchaseOrderController';

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
    supplierId: number;
    approvedBy: string | null;
    canUpdate: boolean;
    canSubmit: boolean;
    canApprove: boolean;
    canRequestRevision: boolean;
    canCancel: boolean;
    canReceive: boolean;
    canReturn: boolean;
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
    pagination: {
        currentPage: number;
        lastPage: number;
        total: number;
        from: number | null;
        to: number | null;
        prevPageUrl: string | null;
        nextPageUrl: string | null;
    };
    filters: { search: string; status: string };
    statusOptions: { value: string; label: string }[];
    suppliers: SupplierOption[];
    items: ItemOption[];
    warehouses: WarehouseOption[];
    canCreate: boolean;
    canApprove: boolean;
    canReceive: boolean;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => {
        const purchaseOrdersUrl = layoutProps.currentTeam
            ? purchaseOrderIndex({
                  current_team: layoutProps.currentTeam.slug,
              }).url
            : purchaseOrderIndex().url;

        return {
            breadcrumbs: [
                { title: 'Purchasing' },
                { title: 'Purchase orders', href: purchaseOrdersUrl },
            ],
        };
    },
});

const page = usePage();
const createOpen = ref(false);
const editingOrder = ref<PurchaseOrder | null>(null);
const actionOrder = ref<PurchaseOrder | null>(null);
const actionKind = ref<'cancel' | 'revision'>('revision');
const actionOpen = ref(false);
const actionReason = ref('');
const actionError = ref('');
const actionSaving = ref(false);
const busyOrderId = ref<number | null>(null);
const listError = ref('');
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const filtering = ref(false);

watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search;
        status.value = filters.status;
    },
);
const receiveOpen = ref(false);
const returning = ref(false);
const saving = ref(false);
const formError = ref('');
const receivingError = ref('');
const idempotencyKey = ref('');
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
const hasValidDraftItems = computed(() => {
    const selectedItemIds = form.value.items.map(
        (line) => line.inventory_item_id,
    );

    return (
        selectedItemIds.length > 0 &&
        selectedItemIds.every((itemId) => itemId !== '') &&
        new Set(selectedItemIds).size === selectedItemIds.length
    );
});

function teamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;
    return team?.slug ?? null;
}

function applyFilters(clear = false): void {
    const slug = teamSlug();
    if (!slug || filtering.value) return;
    if (clear) {
        search.value = '';
        status.value = '';
    }
    filtering.value = true;
    listError.value = '';
    router.get(
        purchaseOrderIndex({ current_team: slug }).url,
        {
            search: search.value.trim() || undefined,
            status: status.value || undefined,
        },
        {
            preserveState: true,
            replace: true,
            onError: (errors) =>
                (listError.value =
                    Object.values(errors)[0] ??
                    'Could not search purchase orders.'),
            onFinish: () => (filtering.value = false),
        },
    );
}

function visitPage(url: string | null): void {
    if (!url || filtering.value) return;
    filtering.value = true;
    router.get(
        url,
        {},
        {
            preserveState: true,
            onFinish: () => (filtering.value = false),
        },
    );
}

function openCreate(): void {
    editingOrder.value = null;
    form.value = {
        supplier_id: '',
        expected_date: new Date().toISOString().slice(0, 10),
        reference: '',
        items: [newLine()],
    };
    formError.value = '';
    createOpen.value = true;
}

function openEdit(order: PurchaseOrder): void {
    editingOrder.value = order;
    form.value = {
        supplier_id: props.suppliers.some(
            (supplier) => supplier.id === order.supplierId,
        )
            ? String(order.supplierId)
            : '',
        expected_date: order.expectedDate,
        reference: order.reference ?? '',
        items: order.items.map((line) => ({
            inventory_item_id:
                line.inventoryItemId &&
                props.items.some((item) => item.id === line.inventoryItemId)
                    ? String(line.inventoryItemId)
                    : '',
            quantity: line.quantity,
            unit_cost: line.unitCost,
        })),
    };
    formError.value = '';
    createOpen.value = true;
}

function newLine(): DraftLine {
    return {
        inventory_item_id: '',
        quantity: 1,
        unit_cost: 0,
    };
}

function isItemSelectedElsewhere(
    itemId: number,
    currentIndex: number,
): boolean {
    return form.value.items.some(
        (line, index) =>
            index !== currentIndex && line.inventory_item_id === String(itemId),
    );
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
    if (
        !slug ||
        saving.value ||
        !form.value.supplier_id ||
        !hasValidDraftItems.value
    ) {
        return;
    }

    saving.value = true;
    formError.value = '';
    const editing = editingOrder.value;
    const endpoint = editing
        ? updatePurchaseOrder({ current_team: slug, purchaseOrder: editing.id })
        : storePurchaseOrder({ current_team: slug });
    router.visit(endpoint.url, {
        method: editing ? 'patch' : 'post',
        data: form.value,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => (createOpen.value = false),
        onError: (errors) =>
            (formError.value =
                Object.values(errors)[0] ?? 'Could not save this order.'),
        onFinish: () => (saving.value = false),
    });
}

function postAction(order: PurchaseOrder, action: 'submit' | 'approve'): void {
    const slug = teamSlug();
    if (!slug || busyOrderId.value !== null) return;
    const endpoint =
        action === 'submit'
            ? submitPurchaseOrder({
                  current_team: slug,
                  purchaseOrder: order.id,
              })
            : approvePurchaseOrder({
                  current_team: slug,
                  purchaseOrder: order.id,
              });
    busyOrderId.value = order.id;
    listError.value = '';
    router.post(
        endpoint.url,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                (listError.value =
                    Object.values(errors)[0] ??
                    'Could not update this purchase order.'),
            onFinish: () => (busyOrderId.value = null),
        },
    );
}

function openAction(order: PurchaseOrder, kind: 'cancel' | 'revision'): void {
    actionOrder.value = order;
    actionKind.value = kind;
    actionReason.value = '';
    actionError.value = '';
    actionOpen.value = true;
}

function saveAction(): void {
    const slug = teamSlug();
    const order = actionOrder.value;
    if (!slug || !order || actionSaving.value || !actionReason.value.trim())
        return;
    const endpoint =
        actionKind.value === 'revision'
            ? requestPurchaseOrderRevision({
                  current_team: slug,
                  purchaseOrder: order.id,
              })
            : cancelPurchaseOrder({
                  current_team: slug,
                  purchaseOrder: order.id,
              });
    actionSaving.value = true;
    actionError.value = '';
    router.post(
        endpoint.url,
        { reason: actionReason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => (actionOpen.value = false),
            onError: (errors) =>
                (actionError.value =
                    Object.values(errors)[0] ??
                    'Could not update this purchase order.'),
            onFinish: () => (actionSaving.value = false),
        },
    );
}

function openReceive(order: PurchaseOrder, line: PurchaseOrderLine): void {
    returning.value = false;
    selectedOrder.value = order;
    selectedLine.value = line;
    const warehouse = props.warehouses.find(
        (option) => option.locations.length > 0,
    );
    receipt.value = {
        warehouse_id: warehouse ? String(warehouse.id) : '',
        warehouse_location_id: warehouse?.locations[0]
            ? String(warehouse.locations[0].id)
            : '',
        quantity: Math.max(1, line.quantity - line.receivedQuantity),
        reason: '',
    };
    receivingError.value = '';
    idempotencyKey.value = crypto.randomUUID();
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
    const slug = teamSlug();
    if (!order || !slug || !line || saving.value) return;

    saving.value = true;
    receivingError.value = '';
    const endpoint = returning.value
        ? returnPurchaseOrderGoods({
              current_team: slug,
              purchaseOrder: order.id,
          })
        : receivePurchaseOrder({ current_team: slug, purchaseOrder: order.id });
    router.post(
        endpoint.url,
        {
            purchase_order_item_id: line.id,
            ...receipt.value,
            ...(returning.value ? { reason: receipt.value.reason } : {}),
            idempotency_key: idempotencyKey.value,
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

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(`${value}T00:00:00`)) : '—';
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
                <Plus data-icon="inline-start" /> New purchase order
            </Button>
        </header>

        <form
            class="flex flex-col gap-3 sm:flex-row sm:items-end"
            role="search"
            @submit.prevent="applyFilters()"
        >
            <div class="grid flex-1 gap-2">
                <Label for="purchase-order-search"
                    >Search purchase orders</Label
                >
                <Input
                    id="purchase-order-search"
                    v-model="search"
                    type="search"
                    maxlength="255"
                    placeholder="PO number, supplier, or reference"
                />
            </div>
            <div class="grid gap-2 sm:w-48">
                <Label for="purchase-order-status">Status</Label>
                <select
                    id="purchase-order-status"
                    v-model="status"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <Button type="submit" variant="outline" :disabled="filtering"
                ><Search data-icon="inline-start" />{{
                    filtering ? 'Searching…' : 'Search'
                }}</Button
            >
            <Button
                v-if="filters.search || filters.status"
                type="button"
                variant="ghost"
                :disabled="filtering"
                @click="applyFilters(true)"
                >Clear filters</Button
            >
        </form>
        <Alert v-if="listError" variant="destructive"
            ><AlertTitle>Could not complete the action</AlertTitle
            ><AlertDescription>{{ listError }}</AlertDescription></Alert
        >

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
                        <p
                            v-if="order.approvedBy"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            Approved by {{ order.approvedBy }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-2 font-semibold">{{
                            currency.format(order.totalAmount)
                        }}</span>
                        <Button
                            v-if="order.canUpdate"
                            variant="outline"
                            size="sm"
                            :disabled="busyOrderId !== null"
                            @click="openEdit(order)"
                        >
                            <Pencil data-icon="inline-start" /> Edit draft
                        </Button>
                        <Button
                            v-if="order.canRequestRevision"
                            variant="outline"
                            size="sm"
                            :disabled="busyOrderId !== null"
                            @click="openAction(order, 'revision')"
                        >
                            <Undo2 data-icon="inline-start" /> Request revision
                        </Button>
                        <Button
                            v-if="order.canSubmit"
                            :disabled="busyOrderId !== null"
                            size="sm"
                            @click="postAction(order, 'submit')"
                        >
                            <Send data-icon="inline-start" /> Submit
                        </Button>
                        <Button
                            v-if="order.canApprove"
                            :disabled="busyOrderId !== null"
                            size="sm"
                            @click="postAction(order, 'approve')"
                        >
                            <Check data-icon="inline-start" /> Approve
                        </Button>
                        <Button
                            v-if="order.canCancel"
                            :disabled="busyOrderId !== null"
                            variant="outline"
                            size="sm"
                            @click="openAction(order, 'cancel')"
                        >
                            <X data-icon="inline-start" /> Cancel
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
                                            order.canReceive &&
                                            line.receivedQuantity <
                                                line.quantity
                                        "
                                        variant="outline"
                                        size="sm"
                                        @click="openReceive(order, line)"
                                    >
                                        <Truck data-icon="inline-start" />
                                        Receive
                                    </Button>
                                    <Button
                                        v-if="
                                            order.canReturn &&
                                            line.receivedQuantity >
                                                line.returnedQuantity
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
            <p class="mt-3 font-medium">
                {{
                    filters.search || filters.status
                        ? 'No matching purchase orders'
                        : 'No purchase orders yet'
                }}
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    filters.search || filters.status
                        ? 'Try another PO number, supplier, reference, or status.'
                        : 'Create a draft to start purchasing from your suppliers.'
                }}
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
                ><Plus data-icon="inline-start" /> New purchase order</Button
            >
        </section>

        <nav
            v-if="pagination.total > 0"
            aria-label="Purchase order pagination"
            class="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <p class="text-muted-foreground">
                Showing {{ pagination.from ?? 0 }}–{{ pagination.to ?? 0 }} of
                {{ pagination.total }} orders
            </p>
            <div class="flex items-center gap-3">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="filtering || !pagination.prevPageUrl"
                    @click="visitPage(pagination.prevPageUrl)"
                    >Previous</Button
                >
                <span
                    >Page {{ pagination.currentPage }} of
                    {{ pagination.lastPage }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="filtering || !pagination.nextPageUrl"
                    @click="visitPage(pagination.nextPageUrl)"
                    >Next</Button
                >
            </div>
        </nav>

        <Dialog v-model:open="actionOpen">
            <DialogContent class="sm:max-w-lg">
                <form class="grid gap-5" @submit.prevent="saveAction">
                    <DialogHeader>
                        <DialogTitle>{{
                            actionKind === 'revision'
                                ? 'Request revision'
                                : 'Cancel purchase order'
                        }}</DialogTitle>
                        <DialogDescription>
                            {{ actionOrder?.number }}:
                            {{
                                actionKind === 'revision'
                                    ? 'Send this order back to draft so it can be corrected and submitted again.'
                                    : 'Cancel the outstanding order. Received stock and its history are retained.'
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="po-action-reason">{{
                            actionKind === 'revision'
                                ? 'Revision reason'
                                : 'Cancellation reason'
                        }}</Label>
                        <Input
                            id="po-action-reason"
                            v-model="actionReason"
                            required
                            maxlength="2000"
                            :aria-invalid="!!actionError"
                        />
                    </div>
                    <p
                        v-if="actionError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ actionError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="actionSaving"
                            @click="actionOpen = false"
                            >Keep order</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                actionKind === 'cancel'
                                    ? 'destructive'
                                    : 'default'
                            "
                            :disabled="actionSaving || !actionReason.trim()"
                            >{{
                                actionSaving
                                    ? 'Saving…'
                                    : actionKind === 'revision'
                                      ? 'Send back for revision'
                                      : 'Cancel order'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="createOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form class="grid gap-5" @submit.prevent="createOrder">
                    <DialogHeader class="text-left">
                        <DialogTitle>{{
                            editingOrder
                                ? 'Edit purchase order'
                                : 'New purchase order'
                        }}</DialogTitle>
                        <DialogDescription>{{
                            editingOrder
                                ? 'Update the supplier, delivery date, reference, and items before submitting for approval.'
                                : 'Create a draft with supplier, expected delivery, reference, and inventory lines.'
                        }}</DialogDescription>
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
                                ><Plus data-icon="inline-start" /> Add
                                item</Button
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
                                        :disabled="
                                            isItemSelectedElsewhere(
                                                item.id,
                                                index,
                                            )
                                        "
                                    >
                                        {{ item.name }} · {{ item.sku }}
                                    </option>
                                </select>
                                <p class="text-xs text-muted-foreground">
                                    Choose a product to fill its current unit
                                    cost; you can adjust the cost for this
                                    order.
                                </p>
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
                                ><X data-icon="inline-start"
                            /></Button>
                        </div>
                        <p class="text-right text-sm font-semibold">
                            Order total: {{ currency.format(draftTotal) }}
                        </p>
                        <p
                            v-if="
                                form.items.some(
                                    (line) => !line.inventory_item_id,
                                )
                            "
                            class="text-xs text-muted-foreground"
                        >
                            Select an inventory item on every line to calculate
                            the order total.
                        </p>
                        <p
                            v-else-if="!hasValidDraftItems"
                            class="text-xs text-muted-foreground"
                        >
                            Each inventory item can appear only once on this
                            draft.
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
                        ><Button
                            type="submit"
                            :disabled="
                                saving ||
                                !form.supplier_id ||
                                !hasValidDraftItems
                            "
                            >{{
                                saving
                                    ? 'Saving…'
                                    : editingOrder
                                      ? 'Save changes'
                                      : 'Create draft'
                            }}</Button
                        ></DialogFooter
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
                    <Alert
                        v-if="
                            !warehouses.length ||
                            !selectedWarehouse?.locations.length
                        "
                    >
                        <AlertTitle>A warehouse bin is required</AlertTitle>
                        <AlertDescription
                            >Select a warehouse with a bin, or add one in
                            Warehouses before recording stock.</AlertDescription
                        >
                    </Alert>
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
                            :disabled="
                                saving ||
                                !receipt.warehouse_id ||
                                !receipt.warehouse_location_id
                            "
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
