<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowDownToLine,
    Boxes,
    GripVertical,
    PackageCheck,
    Plus,
    RotateCcw,
    Warehouse,
} from '@lucide/vue';
import { GridStack, type GridStackWidget } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';
import { nextTick, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import { Button } from '@/components/ui/button';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { dashboard } from '@/routes';
import type { DashboardInvitation, Team } from '@/types';

type WidgetKind = 'metric' | 'movement' | 'alerts' | 'activity' | 'capacity';

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

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    currentTeam?: Team | null;
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

const initialWidgets: DashboardWidget[] = [
    {
        id: 'stock-on-hand',
        title: 'Stock on hand',
        kind: 'metric',
        icon: Boxes,
        x: 0,
        y: 0,
        w: 4,
        h: 2,
        value: '18,420',
        description: 'Sellable units across all locations',
        trend: '+4.8% this week',
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
        value: '12',
        description: 'SKUs below their reorder point',
        trend: '4 need attention',
    },
    {
        id: 'incoming-stock',
        title: 'Incoming stock',
        kind: 'metric',
        icon: ArrowDownToLine,
        x: 8,
        y: 0,
        w: 4,
        h: 2,
        value: '1,240',
        description: 'Units expected this week',
        trend: '3 purchase orders',
    },
    {
        id: 'stock-movement',
        title: 'Stock movement',
        kind: 'movement',
        icon: Activity,
        x: 0,
        y: 2,
        w: 8,
        h: 4,
    },
    {
        id: 'reorder-watch',
        title: 'Reorder watch',
        kind: 'alerts',
        icon: PackageCheck,
        x: 8,
        y: 2,
        w: 4,
        h: 4,
    },
    {
        id: 'recent-activity',
        title: 'Recent activity',
        kind: 'activity',
        icon: Warehouse,
        x: 0,
        y: 6,
        w: 12,
        h: 4,
    },
];

const widgets = ref<DashboardWidget[]>(
    initialWidgets.map((widget) => ({ ...widget })),
);
const isEditing = ref(true);
const hasCapacityWidget = ref(false);
const gridElement = ref<HTMLDivElement | null>(null);

let grid: GridStack | null = null;
let defaultLayout: GridStackWidget[] = [];

const movementBars = [
    { day: 'Mon', received: 48, dispatched: 32 },
    { day: 'Tue', received: 68, dispatched: 42 },
    { day: 'Wed', received: 38, dispatched: 58 },
    { day: 'Thu', received: 78, dispatched: 46 },
    { day: 'Fri', received: 56, dispatched: 72 },
    { day: 'Sat', received: 28, dispatched: 36 },
    { day: 'Sun', received: 44, dispatched: 22 },
];

const reorderItems = [
    { sku: 'BX-2048', name: 'Shipping carton · Large', stock: 8, minimum: 24 },
    { sku: 'GL-1180', name: 'Glass bottle · 500 ml', stock: 14, minimum: 30 },
    { sku: 'PK-0093', name: 'Kraft pouch · Small', stock: 19, minimum: 40 },
];

const activityItems = [
    {
        action: 'Purchase order received',
        reference: 'PO-10428 · North warehouse',
        time: '9:42 AM',
    },
    {
        action: 'Stock transfer completed',
        reference: 'TR-00816 · East → Central',
        time: '9:18 AM',
    },
    {
        action: 'Cycle count submitted',
        reference: 'CC-00193 · 12 SKUs counted',
        time: '8:56 AM',
    },
];

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

async function addCapacityWidget(): Promise<void> {
    if (!grid || hasCapacityWidget.value) {
        return;
    }

    const widget: DashboardWidget = {
        id: 'warehouse-capacity',
        title: 'Warehouse capacity',
        kind: 'capacity',
        icon: Warehouse,
        x: 0,
        y: 10,
        w: 4,
        h: 3,
    };

    widgets.value.push(widget);
    hasCapacityWidget.value = true;
    await nextTick();

    const element = gridElement.value?.querySelector<HTMLElement>(
        `[gs-id="${widget.id}"]`,
    );

    if (element) {
        grid.makeWidget(element);
    }
}

async function resetLayout(): Promise<void> {
    if (!grid) {
        return;
    }

    if (hasCapacityWidget.value) {
        const capacityElement = gridElement.value?.querySelector<HTMLElement>(
            '[gs-id="warehouse-capacity"]',
        );

        if (capacityElement) {
            grid.removeWidget(capacityElement);
        }

        widgets.value = widgets.value.filter(
            (widget) => widget.id !== 'warehouse-capacity',
        );
        hasCapacityWidget.value = false;
        await nextTick();
    }

    grid.load(defaultLayout, false);
    isEditing.value = true;
    grid.enableMove(true);
    grid.enableResize(true);
}
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="props.pendingInvitations && props.pendingInvitations.length > 0"
        :invitations="props.pendingInvitations"
    />

    <main class="flex min-h-full flex-1 flex-col gap-5 p-4 sm:p-6">
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Inventory overview
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Sample warehouse data. Drag and resize widgets to try out
                    your dashboard layout.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="hasCapacityWidget"
                    @click="addCapacityWidget"
                >
                    <Plus class="size-4" />
                    Add widget
                </Button>
                <Button variant="outline" size="sm" @click="resetLayout">
                    <RotateCcw class="size-4" />
                    Reset demo
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

        <div
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
                            class="flex items-center justify-between gap-3 border-b px-4 py-3"
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
                            class="flex flex-1 flex-col justify-between p-4"
                        >
                            <div>
                                <p
                                    class="text-3xl font-semibold tracking-tight tabular-nums"
                                >
                                    {{ widget.value }}
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ widget.description }}
                                </p>
                            </div>
                            <p
                                class="mt-4 text-xs font-medium text-muted-foreground"
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
                                <span class="ml-auto"
                                    >Last 7 days · sample</span
                                >
                            </div>
                            <div
                                class="flex min-h-28 flex-1 items-end justify-around gap-3 border-b px-2 pt-2"
                            >
                                <div
                                    v-for="bar in movementBars"
                                    :key="bar.day"
                                    class="flex h-full min-w-6 flex-1 items-end justify-center gap-1"
                                >
                                    <span
                                        class="w-2.5 rounded-t-sm bg-primary/85 sm:w-4"
                                        :style="{ height: `${bar.received}%` }"
                                        :aria-label="`${bar.day}: ${bar.received}% received`"
                                    />
                                    <span
                                        class="w-2.5 rounded-t-sm bg-muted-foreground/35 sm:w-4"
                                        :style="{
                                            height: `${bar.dispatched}%`,
                                        }"
                                        :aria-label="`${bar.day}: ${bar.dispatched}% dispatched`"
                                    />
                                </div>
                            </div>
                            <div
                                class="mt-2 flex justify-around text-xs text-muted-foreground"
                            >
                                <span
                                    v-for="bar in movementBars"
                                    :key="bar.day"
                                    >{{ bar.day }}</span
                                >
                            </div>
                        </div>

                        <div
                            v-else-if="widget.kind === 'alerts'"
                            class="flex flex-1 flex-col divide-y"
                        >
                            <div
                                v-for="item in reorderItems"
                                :key="item.sku"
                                class="px-4 py-3"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">
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
                                        >{{ item.stock }} left</span
                                    >
                                </div>
                                <div
                                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                                    :aria-label="`${item.stock} of ${item.minimum} units remaining`"
                                >
                                    <div
                                        class="h-full rounded-full bg-destructive"
                                        :style="{
                                            width: `${(item.stock / item.minimum) * 100}%`,
                                        }"
                                    />
                                </div>
                            </div>
                            <p
                                class="mt-auto px-4 py-3 text-xs text-muted-foreground"
                            >
                                Showing 3 of 12 items below minimum.
                            </p>
                        </div>

                        <div
                            v-else-if="widget.kind === 'activity'"
                            class="flex flex-1 flex-col divide-y"
                        >
                            <div
                                v-for="item in activityItems"
                                :key="item.reference"
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
                                            {{ item.action }}
                                        </p>
                                        <p
                                            class="mt-0.5 truncate text-xs text-muted-foreground"
                                        >
                                            {{ item.reference }}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ item.time }}</span
                                >
                            </div>
                        </div>

                        <div
                            v-else
                            class="flex flex-1 flex-col justify-between p-4"
                        >
                            <div>
                                <div
                                    class="flex items-baseline justify-between gap-3"
                                >
                                    <p
                                        class="text-3xl font-semibold tracking-tight tabular-nums"
                                    >
                                        78%
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Sample capacity
                                    </p>
                                </div>
                                <div
                                    class="mt-4 h-2 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full w-[78%] rounded-full bg-primary"
                                    />
                                </div>
                            </div>
                            <p class="mt-4 text-sm text-muted-foreground">
                                North warehouse · 3,120 of 4,000 pallet spaces
                            </p>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            Demo figures are illustrative and are not connected to inventory
            records.
        </p>
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
