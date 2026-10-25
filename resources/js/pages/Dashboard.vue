<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Boxes,
    CircleDollarSign,
    GripVertical,
    PackagePlus,
    PackageCheck,
    RotateCcw,
    Warehouse,
} from '@lucide/vue';
import { GridStack, type GridStackWidget } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';
import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import { Button } from '@/components/ui/button';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { dashboard } from '@/routes';
import type { DashboardInvitation, Team } from '@/types';

type WidgetKind = 'metric' | 'movement' | 'alerts' | 'activity';

type DashboardWidget = {
    id: string;
    title: string;
    kind: WidgetKind;
    icon: Component;
    x: number;
    y: number;
    w: number;
    h: number;
    value?: string;
    description?: string;
    trend?: string;
};

type DashboardSummary = {
    totalItems: number;
    totalUnits: number;
    lowStockCount: number;
    outOfStockCount: number;
    inventoryValue: number;
};

type DashboardReorderItem = {
    sku: string;
    name: string;
    quantity: number;
    reorderPoint: number;
};

type DashboardActivity = {
    id: number;
    description: string;
    itemName: string;
    sku: string;
    userName: string;
    createdAt: string;
};

type DashboardMovement = {
    day: string;
    received: number;
    dispatched: number;
};

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    currentTeam?: Team | null;
    summary?: DashboardSummary;
    reorderItems?: DashboardReorderItem[];
    activities?: DashboardActivity[];
    movement?: DashboardMovement[];
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: layoutProps.currentTeam
                    ? dashboard(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const summary = computed<DashboardSummary>(
    () =>
        props.summary ?? {
            totalItems: 0,
            totalUnits: 0,
            lowStockCount: 0,
            outOfStockCount: 0,
            inventoryValue: 0,
        },
);

const reorderItems = computed<DashboardReorderItem[]>(
    () => props.reorderItems ?? [],
);

const activities = computed<DashboardActivity[]>(() => props.activities ?? []);

const movement = computed<DashboardMovement[]>(() => props.movement ?? []);

const numberFormatter = new Intl.NumberFormat('en-US');
const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

function formatCurrency(value: number): string {
    return currencyFormatter.format(value);
}

function formatActivityTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

const movementMax = computed<number>(() =>
    Math.max(
        1,
        ...movement.value.flatMap((entry) => [
            entry.received,
            entry.dispatched,
        ]),
    ),
);

const hasMovement = computed<boolean>(() =>
    movement.value.some((entry) => entry.received > 0 || entry.dispatched > 0),
);

function barHeight(value: number): string {
    return `${(value / movementMax.value) * 100}%`;
}

function reorderFillPercentage(quantity: number, reorderPoint: number): number {
    if (reorderPoint <= 0) {
        return 0;
    }

    return Math.min(100, (quantity / reorderPoint) * 100);
}

const initialWidgets = computed<DashboardWidget[]>(() => [
    {
        id: 'stock-on-hand',
        title: 'Stock on hand',
        kind: 'metric',
        icon: Boxes,
        x: 0,
        y: 0,
        w: 4,
        h: 2,
        value: formatNumber(summary.value.totalUnits),
        description: `${formatNumber(summary.value.totalItems)} products`,
        trend: `${formatNumber(summary.value.outOfStockCount)} out of stock`,
    },
    {
        id: 'low-stock',
        title: 'Low-stock items',
        kind: 'metric',
        icon: AlertTriangle,
        x: 4,
        y: 0,
        w: 4,
        h: 2,
        value: formatNumber(summary.value.lowStockCount),
        description: 'At or below reorder point',
        trend: `${formatNumber(summary.value.totalItems)} products tracked`,
    },
    {
        id: 'inventory-value',
        title: 'Inventory value',
        kind: 'metric',
        icon: CircleDollarSign,
        x: 8,
        y: 0,
        w: 4,
        h: 2,
        value: formatCurrency(summary.value.inventoryValue),
        description: 'Units at current unit cost',
        trend: `${formatNumber(summary.value.totalItems)} products valued`,
    },
    {
        id: 'stock-movement',
        title: 'Stock movement',
        kind: 'movement',
        icon: Activity,
        x: 0,
        y: 2,
        w: 8,
        h: 3,
    },
    {
        id: 'reorder-watch',
        title: 'Reorder watch',
        kind: 'alerts',
        icon: PackageCheck,
        x: 8,
        y: 2,
        w: 4,
        h: 3,
    },
    {
        id: 'recent-activity',
        title: 'Recent activity',
        kind: 'activity',
        icon: Warehouse,
        x: 0,
        y: 5,
        w: 12,
        h: 3,
    },
]);

const widgets = computed<DashboardWidget[]>(() => initialWidgets.value);
const isEditing = ref(false);
const gridElement = ref<HTMLDivElement | null>(null);

const teamSlug = computed(() => props.currentTeam?.slug ?? null);
const inventoryUrl = computed(() =>
    teamSlug.value ? `/${teamSlug.value}/inventories` : '/inventories',
);
const scanUrl = computed(() =>
    teamSlug.value ? `/${teamSlug.value}/inventory-scans` : '/inventory-scans',
);
const purchaseOrdersUrl = computed(() =>
    teamSlug.value ? `/${teamSlug.value}/purchase-orders` : '/purchase-orders',
);

let grid: GridStack | null = null;
let defaultLayout: GridStackWidget[] = [];

onMounted(() => {
    if (!gridElement.value) {
        return;
    }

    grid = GridStack.init(
        {
            column: 12,
            cellHeight: 76,
            margin: 12,
            draggable: { handle: '.widget-drag-handle' },
            resizable: { handles: 'se' },
            columnOpts: {
                breakpointForWindow: false,
                breakpoints: [
                    { w: 720, c: 1 },
                    { w: 1040, c: 6 },
                ],
            },
        },
        gridElement.value,
    );

    if (grid) {
        grid.enableMove(isEditing.value);
        grid.enableResize(isEditing.value);
        defaultLayout = grid.save(false) as GridStackWidget[];
    }
});

onBeforeUnmount(() => {
    grid?.destroy(false);
    grid = null;
});

function toggleEditing(): void {
    isEditing.value = !isEditing.value;
    grid?.enableMove(isEditing.value);
    grid?.enableResize(isEditing.value);
}

function resetLayout(): void {
    if (!grid) {
        return;
    }

    grid.load(defaultLayout, false);
    isEditing.value = true;
    grid.enableMove(true);
    grid.enableResize(true);
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-5 p-4 sm:p-6">
        <Head title="Dashboard" />

        <PendingInvitationsModal
            v-if="
                props.pendingInvitations && props.pendingInvitations.length > 0
            "
            :invitations="props.pendingInvitations"
        />

        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Inventory overview
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Live inventory data for your team. Common tasks are ready
                    below; dashboard layout editing is optional.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" @click="resetLayout">
                    <RotateCcw class="size-4" />
                    Reset layout
                </Button>
                <Button
                    size="sm"
                    :aria-pressed="isEditing"
                    @click="toggleEditing"
                >
                    {{ isEditing ? 'Lock layout' : 'Edit layout' }}
                </Button>
            </div>
        </header>

        <section
            class="rounded-lg border bg-card p-4 sm:p-5"
            aria-labelledby="start-tasks-title"
        >
            <div class="mb-3">
                <h2 id="start-tasks-title" class="font-medium">
                    Start with a task
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Go straight to the work you need to do.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <Link :href="inventoryUrl"
                        ><Boxes class="size-4" /> Find an item</Link
                    >
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link :href="scanUrl"
                        ><PackagePlus class="size-4" /> Receive or move
                        stock</Link
                    >
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link :href="purchaseOrdersUrl"
                        ><PackageCheck class="size-4" /> Create a purchase
                        order</Link
                    >
                </Button>
            </div>
        </section>

        <div
            v-if="isEditing"
            class="flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
            role="status"
        >
            <GripVertical class="size-4 shrink-0" />
            <span v-if="isEditing">
                Layout editing is on. Drag widgets by the grip; resize from the
                lower-right corner.
            </span>
            <span v-else
                >Layout locked. Select Edit layout to move or resize
                widgets.</span
            >
        </div>

        <div ref="gridElement" class="grid-stack inventory-grid">
            <div
                v-for="widget in widgets"
                :key="widget.id"
                class="grid-stack-item"
                :gs-id="widget.id"
                :gs-x="widget.x"
                :gs-y="widget.y"
                :gs-w="widget.w"
                :gs-h="widget.h"
            >
                <div class="grid-stack-item-content">
                    <article
                        class="flex h-full flex-col overflow-hidden rounded-lg border bg-card text-card-foreground"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b px-4 py-2"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <component
                                    :is="widget.icon"
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <h2 class="truncate text-sm font-medium">
                                    {{ widget.title }}
                                </h2>
                            </div>
                            <button
                                type="button"
                                class="widget-drag-handle inline-flex size-8 shrink-0 cursor-grab items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                                :aria-label="`Move ${widget.title} widget`"
                                :disabled="!isEditing"
                                :class="{
                                    'pointer-events-none opacity-40':
                                        !isEditing,
                                }"
                            >
                                <GripVertical class="size-4" />
                            </button>
                        </header>

                        <div
                            v-if="widget.kind === 'metric'"
                            class="flex flex-1 flex-col justify-between p-3"
                        >
                            <div>
                                <p
                                    class="text-2xl font-semibold tracking-tight tabular-nums"
                                >
                                    {{ widget.value }}
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ widget.description }}
                                </p>
                            </div>
                            <p
                                v-if="widget.trend"
                                class="mt-2 text-xs font-medium text-muted-foreground"
                            >
                                {{ widget.trend }}
                            </p>
                        </div>

                        <div
                            v-else-if="widget.kind === 'movement'"
                            class="flex min-h-0 flex-1 flex-col p-4"
                        >
                            <div
                                class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
                            >
                                <span class="inline-flex items-center gap-1.5"
                                    ><span
                                        class="size-2 rounded-sm bg-primary"
                                    />
                                    Received</span
                                >
                                <span class="inline-flex items-center gap-1.5"
                                    ><span
                                        class="size-2 rounded-sm bg-muted-foreground/40"
                                    />
                                    Dispatched</span
                                >
                                <span class="ml-auto">Last 7 days</span>
                            </div>

                            <div
                                v-if="hasMovement"
                                class="flex min-h-28 flex-1 items-end justify-around gap-3 border-b px-2 pt-2"
                            >
                                <div
                                    v-for="bar in movement"
                                    :key="bar.day"
                                    class="flex h-full min-w-6 flex-1 items-end justify-center gap-1"
                                >
                                    <span
                                        class="w-2.5 rounded-t-sm bg-primary/85 sm:w-4"
                                        :style="{
                                            height: barHeight(bar.received),
                                        }"
                                        :aria-label="`${bar.day}: ${bar.received} received`"
                                    />
                                    <span
                                        class="w-2.5 rounded-t-sm bg-muted-foreground/35 sm:w-4"
                                        :style="{
                                            height: barHeight(bar.dispatched),
                                        }"
                                        :aria-label="`${bar.day}: ${bar.dispatched} dispatched`"
                                    />
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex min-h-28 flex-1 items-center justify-center border-b px-2 text-sm text-muted-foreground"
                            >
                                No stock movement in the last 7 days.
                            </div>

                            <div
                                class="mt-2 flex justify-around text-xs text-muted-foreground"
                            >
                                <span v-for="bar in movement" :key="bar.day">{{
                                    bar.day
                                }}</span>
                            </div>
                        </div>

                        <div
                            v-else-if="widget.kind === 'alerts'"
                            class="flex flex-1 flex-col divide-y"
                        >
                            <template v-if="reorderItems.length > 0">
                                <div
                                    v-for="item in reorderItems"
                                    :key="item.sku"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="truncate text-sm font-medium"
                                            >
                                                {{ item.name }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                {{ item.sku }}
                                            </p>
                                        </div>
                                        <span
                                            class="shrink-0 text-xs font-medium text-destructive"
                                            >{{ item.quantity }} of
                                            {{ item.reorderPoint }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                                        :aria-label="`${item.quantity} of ${item.reorderPoint} reorder point units remaining`"
                                    >
                                        <div
                                            class="h-full rounded-full bg-destructive"
                                            :style="{
                                                width: `${reorderFillPercentage(item.quantity, item.reorderPoint)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <p
                                    class="mt-auto px-4 py-3 text-xs text-muted-foreground"
                                >
                                    Showing {{ reorderItems.length }} of
                                    {{ summary.lowStockCount }} low-stock items.
                                </p>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 items-center justify-center px-4 py-6 text-center text-sm text-muted-foreground"
                            >
                                No items are at or below their reorder point.
                            </div>
                        </div>

                        <div
                            v-else-if="widget.kind === 'activity'"
                            class="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto"
                        >
                            <template v-if="activities.length > 0">
                                <div
                                    v-for="item in activities"
                                    :key="item.id"
                                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span
                                            class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                                        >
                                            <PackageCheck class="size-3.5" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium">
                                                {{ item.description }}
                                            </p>
                                            <p
                                                class="mt-0.5 truncate text-xs text-muted-foreground"
                                            >
                                                {{
                                                    item.itemName ||
                                                    item.sku ||
                                                    'Unknown item'
                                                }}
                                                <template
                                                    v-if="
                                                        item.itemName &&
                                                        item.sku
                                                    "
                                                >
                                                    · {{ item.sku }}
                                                </template>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatActivityTime(
                                                    item.createdAt,
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ item.userName }}
                                        </p>
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 items-center justify-center px-4 py-6 text-center text-sm text-muted-foreground"
                            >
                                No inventory activity yet.
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </main>
</template>

<style>
.inventory-grid.grid-stack {
    --gs-item-margin-bottom: 12px;
}

.inventory-grid > .grid-stack-item > .grid-stack-item-content {
    inset: 0;
    overflow: visible;
}

.inventory-grid .ui-resizable-se {
    right: 3px;
    bottom: 3px;
    width: 14px;
    height: 14px;
    cursor: nwse-resize;
}
</style>
