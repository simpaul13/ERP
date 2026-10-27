<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    Boxes,
    PackageCheck,
    ReceiptText,
    ShoppingCart,
    Truck,
} from '@lucide/vue';
import { computed, type Component } from 'vue';
import { index as purchaseOrderIndex } from '@/actions/App/Http/Controllers/Purchases/PurchaseOrderController';
import { show as salesOrderShow } from '@/actions/App/Http/Controllers/Sales/SalesOrderController';

type Item = {
    id: number;
    sku: string;
    name: string;
    quantity: number;
    reorder_point?: number;
};

type Order = {
    id: number;
    status: string;
    customer_name?: string;
    supplier?: { name: string };
};

type ActivityRecord = Record<string, unknown> & {
    id?: number;
    description?: string;
    event?: string;
    subject_type?: string;
    created_at?: string;
};

type StockAlert = Item & { alert: 'Out of stock' | 'Low stock' };

const props = defineProps<{
    lowStockItems: Item[];
    outOfStockItems: Item[];
    pendingSalesOrders: Order[];
    pendingPurchaseOrders: Order[];
    activities: ActivityRecord[];
}>();

const page = usePage();
const slug = computed(
    () => (page.props.currentTeam as { slug: string } | null)?.slug ?? '',
);

const reportLinks: {
    label: string;
    description: string;
    path: string;
    icon: Component;
}[] = [
    {
        label: 'Inventory',
        description: 'Current levels by item and warehouse',
        path: 'inventory',
        icon: Boxes,
    },
    {
        label: 'Stock movements',
        description: 'Receipts and dispatches',
        path: 'stock-movements',
        icon: Activity,
    },
    {
        label: 'Sales summary',
        description: 'Order counts and totals',
        path: 'sales-summary',
        icon: ReceiptText,
    },
    {
        label: 'Purchase summary',
        description: 'Order counts and totals',
        path: 'purchase-summary',
        icon: ShoppingCart,
    },
];

const stockAlerts = computed<StockAlert[]>(() => [
    ...props.outOfStockItems.map((item) => ({
        ...item,
        alert: 'Out of stock' as const,
    })),
    ...props.lowStockItems.map((item) => ({
        ...item,
        alert: 'Low stock' as const,
    })),
]);

const openOrderCount = computed(
    () => props.pendingSalesOrders.length + props.pendingPurchaseOrders.length,
);

const inventoryReport = computed(() => `/${slug.value}/reports/inventory`);
const stockMovementsReport = computed(
    () => `/${slug.value}/reports/stock-movements`,
);
const inventoryItem = (id: number) => `/${slug.value}/inventories/${id}`;
const salesOrder = (id: number) =>
    salesOrderShow.url({ current_team: slug.value, salesOrder: id });
const purchaseOrder = (id: number) =>
    purchaseOrderIndex.url(
        { current_team: slug.value },
        { query: { search: `PO-${id}` } },
    );

function statusLabel(status: string): string {
    return status
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function activityDescription(activity: ActivityRecord): string {
    return (activity.description ??
        activity.event ??
        activity.subject_type ??
        'Inventory activity') as string;
}

function formatActivityDate(value: unknown): string {
    if (typeof value !== 'string' || value.trim() === '') {
        return 'Date not recorded';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Date not recorded';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
</script>

<template>
    <main
        class="mx-auto flex min-h-full w-full max-w-7xl flex-1 flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8 lg:px-8"
    >
        <Head title="Reports & Operations" />

        <header class="flex flex-col gap-5">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Reports &amp; Operations
                </h1>
                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground sm:text-base"
                >
                    A focused view of stock alerts, open orders, and recent
                    activity.
                </p>
            </div>

            <nav
                aria-label="Reports"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <ul
                    class="grid grid-cols-1 divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4"
                >
                    <li
                        v-for="report in reportLinks"
                        :key="report.path"
                        class="min-w-0"
                    >
                        <Link
                            :href="`/${slug}/reports/${report.path}`"
                            class="group flex h-full items-center gap-3 px-4 py-4 transition-colors hover:bg-accent/60 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            >
                                <component
                                    :is="report.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{
                                    report.label
                                }}</span>
                                <span
                                    class="mt-0.5 block text-xs text-muted-foreground"
                                    >{{ report.description }}</span
                                >
                            </span>
                            <ArrowUpRight
                                class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </nav>
        </header>

        <div class="grid gap-6 xl:grid-cols-2">
            <section
                aria-labelledby="stock-alerts-title"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <header
                    class="flex items-center justify-between gap-4 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <AlertTriangle
                                class="size-[18px]"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <h2 id="stock-alerts-title" class="font-semibold">
                                Stock alerts
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Items at or below their reorder point
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums"
                    >
                        {{ stockAlerts.length }}
                        <span class="sr-only">stock alerts</span>
                    </span>
                </header>

                <ul v-if="stockAlerts.length" class="divide-y">
                    <li
                        v-for="item in stockAlerts"
                        :key="`${item.alert}-${item.id}`"
                        class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="inventoryItem(item.id)"
                                class="font-medium underline-offset-4 hover:underline focus-visible:rounded-sm"
                            >
                                {{ item.name }}
                            </Link>
                            <p class="mt-1 text-xs text-muted-foreground">
                                SKU {{ item.sku }}
                            </p>
                        </div>
                        <div
                            class="flex shrink-0 items-center justify-between gap-4 sm:justify-end"
                        >
                            <span
                                class="text-xs font-medium"
                                :class="
                                    item.alert === 'Out of stock'
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ item.alert }}
                            </span>
                            <span class="text-sm tabular-nums">
                                <strong class="font-semibold">{{
                                    item.quantity
                                }}</strong>
                                <span class="text-muted-foreground">
                                    on hand</span
                                >
                                <span
                                    v-if="item.reorder_point !== undefined"
                                    class="text-muted-foreground"
                                >
                                    · Reorder at {{ item.reorder_point }}
                                </span>
                            </span>
                        </div>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-start gap-3 px-5 py-6 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-start gap-3">
                        <PackageCheck
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="text-sm font-medium">No stock alerts</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                No low- or out-of-stock items are currently
                                reported.
                            </p>
                        </div>
                    </div>
                    <Link
                        :href="inventoryReport"
                        class="inline-flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        View inventory
                        <ArrowUpRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </section>

            <section
                aria-labelledby="open-orders-title"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <header
                    class="flex items-center justify-between gap-4 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <ReceiptText
                                class="size-[18px]"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <h2 id="open-orders-title" class="font-semibold">
                                Open orders
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Orders that may need follow-up
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums"
                    >
                        {{ openOrderCount }}
                        <span class="sr-only">open orders</span>
                    </span>
                </header>

                <div
                    class="grid divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 px-5 pt-4 pb-3">
                            <ReceiptText
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <h3 class="text-sm font-medium">Sales</h3>
                            <span
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                                >{{ pendingSalesOrders.length }}</span
                            >
                        </div>
                        <ul v-if="pendingSalesOrders.length" class="divide-y">
                            <li
                                v-for="order in pendingSalesOrders"
                                :key="order.id"
                                class="flex items-center justify-between gap-3 px-5 py-3"
                            >
                                <Link
                                    :href="salesOrder(order.id)"
                                    class="min-w-0 truncate text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    {{
                                        order.customer_name ||
                                        `Order #${order.id}`
                                    }}
                                    <span class="text-muted-foreground"
                                        >#{{ order.id }}</span
                                    >
                                </Link>
                                <span
                                    class="shrink-0 rounded-md border px-2 py-1 text-xs text-muted-foreground"
                                    >{{ statusLabel(order.status) }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-else
                            class="px-5 pt-1 pb-5 text-sm text-muted-foreground"
                        >
                            No open sales orders.
                        </p>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2 px-5 pt-4 pb-3">
                            <Truck
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <h3 class="text-sm font-medium">Purchases</h3>
                            <span
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                                >{{ pendingPurchaseOrders.length }}</span
                            >
                        </div>
                        <ul
                            v-if="pendingPurchaseOrders.length"
                            class="divide-y"
                        >
                            <li
                                v-for="order in pendingPurchaseOrders"
                                :key="order.id"
                                class="flex items-center justify-between gap-3 px-5 py-3"
                            >
                                <Link
                                    :href="purchaseOrder(order.id)"
                                    class="min-w-0 truncate text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    {{
                                        order.supplier?.name ||
                                        `Purchase order #${order.id}`
                                    }}
                                    <span
                                        v-if="order.supplier?.name"
                                        class="text-muted-foreground"
                                        >#{{ order.id }}</span
                                    >
                                </Link>
                                <span
                                    class="shrink-0 rounded-md border px-2 py-1 text-xs text-muted-foreground"
                                    >{{ statusLabel(order.status) }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-else
                            class="px-5 pt-1 pb-5 text-sm text-muted-foreground"
                        >
                            No open purchase orders.
                        </p>
                    </div>
                </div>
            </section>

            <section
                aria-labelledby="recent-activity-title"
                class="overflow-hidden rounded-xl border bg-card xl:col-span-2"
            >
                <header class="flex items-center gap-3 border-b px-5 py-4">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <Activity class="size-[18px]" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 id="recent-activity-title" class="font-semibold">
                            Recent activity
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Latest inventory updates for this team
                        </p>
                    </div>
                </header>

                <ul v-if="activities.length" class="divide-y">
                    <li
                        v-for="(activity, index) in activities"
                        :key="activity.id ?? index"
                        class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                        <p class="text-sm">
                            {{ activityDescription(activity) }}
                        </p>
                        <time
                            class="shrink-0 text-xs text-muted-foreground"
                            :datetime="activity.created_at"
                            >{{ formatActivityDate(activity.created_at) }}</time
                        >
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-start gap-3 px-5 py-6 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-start gap-3">
                        <Activity
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="text-sm font-medium">
                                No recent activity
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Inventory updates will appear here when
                                recorded.
                            </p>
                        </div>
                    </div>
                    <Link
                        :href="stockMovementsReport"
                        class="inline-flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        View stock movements
                        <ArrowUpRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </section>
        </div>
    </main>
</template>
