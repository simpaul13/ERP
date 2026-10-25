<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import SalesOrderField from '@/components/SalesOrderField.vue';
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
import { index, show, store } from '@/routes/sales-orders';
import {
    salesDate,
    salesMoney,
    salesSelectClass,
    type SalesOrderSummary,
} from '@/types/sales-orders';

const props = defineProps<{
    orders: SalesOrderSummary[];
    canCreate: boolean;
    items: { id: number; name: string; sku: string }[];
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: layoutProps.currentTeam
            ? [
                  {
                      title: 'Sales orders',
                      href: index.url({
                          current_team: layoutProps.currentTeam.slug,
                      }),
                  },
              ]
            : [],
    }),
});

const page = usePage();
const teamSlug = computed(
    () => (page.props.currentTeam as { slug: string } | null)?.slug ?? '',
);
const createOpen = ref(false);
function newDraftData() {
    return {
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        customer_address: '',
        currency: 'PHP',
        items: [{ inventory_item_id: '', quantity: 1, unit_price: '0.00' }],
    };
}

const form = useForm(newDraftData());
const errors = computed(() => form.errors as Record<string, string>);
const draftTotal = computed(() => {
    const cents = form.items.reduce((total, line) => {
        if (
            !/^\d+(\.\d{1,2})?$/.test(String(line.unit_price)) ||
            !Number.isSafeInteger(line.quantity) ||
            line.quantity < 1
        )
            return total;
        const [whole, fraction = ''] = String(line.unit_price).split('.');
        return (
            total +
            (BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))) *
                BigInt(line.quantity)
        );
    }, 0n);
    return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
});
const validItems = computed(() => {
    const ids = form.items.map((line) => line.inventory_item_id);
    return (
        ids.length > 0 && ids.every(Boolean) && new Set(ids).size === ids.length
    );
});

function openCreate(): void {
    form.defaults(newDraftData()).resetAndClearErrors();
    createOpen.value = true;
}

function createOrder(): void {
    if (form.processing || !teamSlug.value || !validItems.value) return;
    form.transform((data) => ({
        ...data,
        currency: 'PHP',
        items: data.items.map((line) => ({
            ...line,
            unit_price: String(line.unit_price),
        })),
    })).post(store.url({ current_team: teamSlug.value }), {
        onSuccess: () => {
            createOpen.value = false;
        },
    });
}

function selectedElsewhere(id: number, index: number): boolean {
    return form.items.some(
        (line, position) =>
            position !== index && line.inventory_item_id === String(id),
    );
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Sales orders" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Sales orders
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create customer orders and track stock reservations,
                    fulfillment, returns, and refunds.
                </p>
            </div>
            <Button
                v-if="canCreate"
                :disabled="!items.length || !teamSlug"
                @click="openCreate"
                ><Plus data-icon="inline-start" /> New sales order</Button
            >
        </header>

        <section
            v-if="orders.length"
            aria-label="Sales orders"
            class="grid gap-4"
        >
            <article
                v-for="order in orders"
                :key="order.id"
                class="rounded-lg border bg-card p-4 sm:p-5"
            >
                <div
                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold">
                                <Link
                                    :href="
                                        show({
                                            current_team: teamSlug,
                                            salesOrder: order.id,
                                        })
                                    "
                                    class="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >{{ order.number }}</Link
                                >
                            </h2>
                            <Badge
                                :variant="
                                    order.status === 'cancelled'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                >{{ order.statusLabel }}</Badge
                            >
                        </div>
                        <p class="mt-1 font-medium break-words">
                            {{ order.customerName }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Created {{ salesDate(order.createdAt) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-semibold tabular-nums">{{
                            salesMoney(order.totalAmount, order.currency)
                        }}</span>
                        <Button variant="outline" as-child
                            ><Link
                                :href="
                                    show({
                                        current_team: teamSlug,
                                        salesOrder: order.id,
                                    })
                                "
                                :aria-label="`View sales order ${order.number}`"
                                >View order</Link
                            ></Button
                        >
                    </div>
                </div>
            </article>
        </section>
        <section
            v-else
            class="rounded-lg border border-dashed px-6 py-12 text-center"
            aria-labelledby="sales-empty-title"
        >
            <h2 id="sales-empty-title" class="font-medium">
                No sales orders yet
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Create a draft with customer details and item prices to start
                selling.
            </p>
            <p v-if="!items.length" class="mt-3 text-sm text-muted-foreground">
                Add an inventory item before creating a sales order.
            </p>
            <Button
                v-if="canCreate && items.length"
                class="mt-4"
                @click="openCreate"
                >New sales order</Button
            >
        </section>

        <Dialog
            :open="createOpen"
            @update:open="
                (open) => {
                    if (!form.processing) createOpen = open;
                }
            "
        >
            <DialogContent
                class="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
                @interact-outside="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
                @escape-key-down="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
            >
                <form
                    class="flex flex-col gap-5"
                    :aria-busy="form.processing"
                    @submit.prevent="createOrder"
                >
                    <DialogHeader class="text-left">
                        <DialogTitle>New sales order</DialogTitle>
                        <DialogDescription
                            >Customer details and prices are saved as an order
                            snapshot. No taxes or discounts are
                            applied.</DialogDescription
                        >
                    </DialogHeader>
                    <fieldset
                        :disabled="form.processing"
                        class="grid min-w-0 gap-4 sm:grid-cols-2"
                    >
                        <legend class="sr-only">Customer and currency</legend>
                        <SalesOrderField
                            id="so-customer-name"
                            v-slot="field"
                            label="Customer name"
                            :error="errors.customer_name"
                        >
                            <Input
                                id="so-customer-name"
                                v-model="form.customer_name"
                                required
                                maxlength="255"
                                autocomplete="name"
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <SalesOrderField
                            id="so-currency"
                            v-slot="field"
                            label="Currency"
                            :error="errors.currency"
                            hint="All sales prices and refund records use Philippine pesos."
                        >
                            <Input
                                id="so-currency"
                                model-value="PHP — Philippine peso"
                                readonly
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <SalesOrderField
                            id="so-email"
                            v-slot="field"
                            label="Customer email (optional)"
                            :error="errors.customer_email"
                        >
                            <Input
                                id="so-email"
                                v-model="form.customer_email"
                                type="email"
                                maxlength="255"
                                autocomplete="email"
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <SalesOrderField
                            id="so-phone"
                            v-slot="field"
                            label="Customer phone (optional)"
                            :error="errors.customer_phone"
                        >
                            <Input
                                id="so-phone"
                                v-model="form.customer_phone"
                                type="tel"
                                maxlength="50"
                                autocomplete="tel"
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <SalesOrderField
                            id="so-address"
                            v-slot="field"
                            label="Customer address (optional)"
                            :error="errors.customer_address"
                            class="sm:col-span-2"
                        >
                            <Input
                                id="so-address"
                                v-model="form.customer_address"
                                maxlength="2000"
                                autocomplete="street-address"
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                    </fieldset>
                    <fieldset
                        :disabled="form.processing"
                        class="flex min-w-0 flex-col gap-3"
                    >
                        <legend class="mb-3 font-medium">Order items</legend>
                        <div
                            v-for="(line, position) in form.items"
                            :key="position"
                            class="grid min-w-0 gap-3 rounded-md border p-3 sm:grid-cols-[minmax(0,1fr)_100px_130px_auto] sm:items-start"
                        >
                            <SalesOrderField
                                :id="`so-item-${position}`"
                                v-slot="field"
                                :label="`Inventory item ${position + 1}`"
                                :error="
                                    errors[
                                        `items.${position}.inventory_item_id`
                                    ]
                                "
                            >
                                <select
                                    :id="`so-item-${position}`"
                                    v-model="line.inventory_item_id"
                                    required
                                    :class="salesSelectClass"
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                >
                                    <option value="" disabled>
                                        Select item
                                    </option>
                                    <option
                                        v-for="item in items"
                                        :key="item.id"
                                        :value="String(item.id)"
                                        :disabled="
                                            selectedElsewhere(item.id, position)
                                        "
                                    >
                                        {{ item.name }} · {{ item.sku }}
                                    </option>
                                </select>
                            </SalesOrderField>
                            <SalesOrderField
                                :id="`so-quantity-${position}`"
                                v-slot="field"
                                :label="`Quantity ${position + 1}`"
                                :error="errors[`items.${position}.quantity`]"
                            >
                                <Input
                                    :id="`so-quantity-${position}`"
                                    v-model.number="line.quantity"
                                    type="number"
                                    min="1"
                                    max="1000000"
                                    step="1"
                                    required
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                />
                            </SalesOrderField>
                            <SalesOrderField
                                :id="`so-price-${position}`"
                                v-slot="field"
                                :label="`Unit price ${position + 1} (PHP)`"
                                :error="errors[`items.${position}.unit_price`]"
                            >
                                <Input
                                    :id="`so-price-${position}`"
                                    v-model="line.unit_price"
                                    type="number"
                                    min="0"
                                    max="999999.99"
                                    step="0.01"
                                    required
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                />
                            </SalesOrderField>
                            <Button
                                type="button"
                                variant="ghost"
                                class="sm:mt-6"
                                :disabled="form.items.length === 1"
                                :aria-label="`Remove item ${position + 1}`"
                                @click="form.items.splice(position, 1)"
                                ><X
                            /></Button>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            class="self-start"
                            :disabled="
                                form.items.length >= Math.min(items.length, 100)
                            "
                            @click="
                                form.items.push({
                                    inventory_item_id: '',
                                    quantity: 1,
                                    unit_price: '0.00',
                                })
                            "
                            ><Plus data-icon="inline-start" /> Add item</Button
                        >
                        <p
                            class="text-right font-semibold tabular-nums"
                            aria-live="polite"
                        >
                            Order total: {{ salesMoney(draftTotal, 'PHP') }}
                        </p>
                    </fieldset>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        aria-label="Order validation errors"
                        class="flex flex-col gap-1 text-sm text-destructive"
                    >
                        <li v-for="(error, key) in errors" :key="key">
                            {{ error }}
                        </li>
                    </ul>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="createOpen = false"
                            >Close</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                form.processing || !validItems || !teamSlug
                            "
                            >{{
                                form.processing ? 'Creating…' : 'Create draft'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
