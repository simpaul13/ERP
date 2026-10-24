<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowLeft,
    ArrowUpFromLine,
    Boxes,
    CalendarDays,
    CircleDollarSign,
    History,
    MapPin,
    Package,
    Warehouse as WarehouseIcon,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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

type ProductImage = {
    id: string;
    name: string;
    src: string;
};

type ItemStock = {
    warehouseId: number;
    warehouseName: string | null;
    warehouseCode: string | null;
    locationId: number;
    locationName: string | null;
    locationCode: string | null;
    quantity: number;
};

type InventoryItem = {
    id: number;
    sku: string;
    name: string;
    category: string;
    quantity: number;
    reorderPoint: number;
    unitCost: number;
    stock: ItemStock[];
    images: ProductImage[];
    mainImageId: string | null;
    createdAt: string | null;
    updatedAt: string | null;
};

type WarehouseBin = {
    id: number;
    code: string;
    name: string;
    isDefault: boolean;
    totalUnits: number;
};

type WarehouseOption = {
    id: number;
    code: string;
    name: string;
    isDefault: boolean;
    totalUnits: number;
    locations: WarehouseBin[];
};

type StockMovementRecord = {
    id: number;
    type: string;
    typeLabel: string;
    itemName: string;
    sku: string;
    warehouseName: string | null;
    locationName: string | null;
    quantity: number;
    quantityBefore: number;
    quantityAfter: number;
    unitCost: number;
    reference: string | null;
    reason: string | null;
    userName: string;
    createdAt: string | null;
};

const props = defineProps<{
    item: InventoryItem;
    movements: StockMovementRecord[];
    warehouses: WarehouseOption[];
    defaultWarehouseId: number | null;
    movementCount: number;
    canManage: boolean;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Inventory',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/inventories`
                    : '/inventories',
            },
            { title: 'Item details', href: '#' },
        ],
    }),
});

const page = usePage();

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

function currentTeamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;

    return team?.slug ?? null;
}

const inventoryUrl = computed<string>(() => {
    const slug = currentTeamSlug();

    return slug ? `/${slug}/inventories` : '/inventories';
});

const warehousesUrl = computed<string>(() => {
    const slug = currentTeamSlug();

    return slug ? `/${slug}/warehouses` : '/warehouses';
});

const selectedImageId = ref<string | null>(
    props.item.mainImageId ?? props.item.images[0]?.id ?? null,
);

const selectedImage = computed<ProductImage | undefined>(() =>
    props.item.images.find((image) => image.id === selectedImageId.value),
);

const stockStatus = computed<string>(() => {
    if (props.item.quantity === 0) {
        return 'Out of stock';
    }

    return props.item.quantity <= props.item.reorderPoint
        ? 'Low stock'
        : 'In stock';
});

const statusBadgeVariant = computed<'default' | 'secondary' | 'destructive'>(
    () => {
        if (stockStatus.value === 'Out of stock') {
            return 'destructive';
        }

        return stockStatus.value === 'Low stock' ? 'secondary' : 'default';
    },
);

const stockValue = computed<number>(
    () => props.item.quantity * props.item.unitCost,
);

type WarehouseStock = {
    id: number;
    name: string;
    code: string | null;
    totalUnits: number;
    locations: ItemStock[];
};

const stockByWarehouse = computed<WarehouseStock[]>(() => {
    const groups = new Map<number, WarehouseStock>();

    props.item.stock.forEach((entry) => {
        const group = groups.get(entry.warehouseId) ?? {
            id: entry.warehouseId,
            name: entry.warehouseName ?? 'Warehouse',
            code: entry.warehouseCode,
            totalUnits: 0,
            locations: [],
        };

        group.totalUnits += entry.quantity;
        group.locations.push(entry);
        groups.set(entry.warehouseId, group);
    });

    return Array.from(groups.values()).sort(
        (first, second) => second.totalUnits - first.totalUnits,
    );
});

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function formatCurrency(value: number): string {
    return currencyFormatter.format(value);
}

function binLabel(entry: ItemStock): string {
    return [entry.locationName, entry.locationCode]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

/**
 * Stock adjustment dialog state.
 */
const adjustmentOpen = ref(false);
const adjustmentDirection = ref<'receive' | 'remove'>('receive');
const adjustmentAmount = ref(0);
const adjustmentReason = ref('');
const adjustmentReference = ref('');
const adjustmentWarehouseId = ref<number | null>(null);
const adjustmentLocationId = ref<number | null>(null);
const adjustmentError = ref('');
const adjusting = ref(false);

function defaultBinId(warehouseId: number | null): number | null {
    if (!warehouseId) {
        return null;
    }

    const warehouse = props.warehouses.find(
        (entry) => entry.id === warehouseId,
    );

    return (
        warehouse?.locations.find((location) => location.isDefault)?.id ??
        warehouse?.locations[0]?.id ??
        null
    );
}

const adjustmentWarehouse = computed<WarehouseOption | undefined>(() =>
    props.warehouses.find(
        (warehouse) => warehouse.id === adjustmentWarehouseId.value,
    ),
);

const adjustmentLocations = computed<WarehouseBin[]>(
    () => adjustmentWarehouse.value?.locations ?? [],
);

const adjustmentAvailable = computed<number>(() =>
    props.item.stock
        .filter(
            (entry) =>
                entry.warehouseId === adjustmentWarehouseId.value &&
                entry.locationId === adjustmentLocationId.value,
        )
        .reduce((total, entry) => total + entry.quantity, 0),
);

watch(adjustmentWarehouseId, (warehouseId) => {
    const belongsToWarehouse = adjustmentLocations.value.some(
        (location) => location.id === adjustmentLocationId.value,
    );

    if (!belongsToWarehouse) {
        adjustmentLocationId.value = defaultBinId(warehouseId);
    }
});

function openAdjustment(direction: 'receive' | 'remove'): void {
    const located = props.item.stock.find((entry) => entry.quantity > 0);
    const warehouseId =
        located?.warehouseId ??
        props.defaultWarehouseId ??
        props.warehouses[0]?.id ??
        null;

    adjustmentDirection.value = direction;
    adjustmentAmount.value = 0;
    adjustmentReason.value = '';
    adjustmentReference.value = '';
    adjustmentWarehouseId.value = warehouseId;
    adjustmentLocationId.value =
        located?.locationId ?? defaultBinId(warehouseId);
    adjustmentError.value = '';
    adjustmentOpen.value = true;
}

function firstError(errors: Record<string, string>): string {
    return (
        Object.values(errors)[0] ?? 'Something went wrong. Please try again.'
    );
}

function adjustStock(): void {
    const slug = currentTeamSlug();

    if (!slug || adjusting.value) {
        return;
    }

    const amount = Number(adjustmentAmount.value);

    if (!Number.isFinite(amount) || amount <= 0) {
        adjustmentError.value = 'Enter a quantity greater than zero.';

        return;
    }

    if (!adjustmentWarehouseId.value) {
        adjustmentError.value = 'Choose a warehouse.';

        return;
    }

    if (
        adjustmentDirection.value === 'remove' &&
        !adjustmentReason.value.trim()
    ) {
        adjustmentError.value = 'A reason is required when removing stock.';

        return;
    }

    adjustmentError.value = '';
    adjusting.value = true;

    router.post(
        `/${slug}/inventories/${props.item.id}/stock`,
        {
            direction: adjustmentDirection.value,
            warehouse_id: adjustmentWarehouseId.value,
            warehouse_location_id: adjustmentLocationId.value,
            amount,
            reason: adjustmentReason.value.trim(),
            reference: adjustmentReference.value.trim(),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                adjustmentOpen.value = false;
            },
            onError: (errors) => {
                adjustmentError.value = firstError(errors);
            },
            onFinish: () => {
                adjusting.value = false;
            },
        },
    );
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head :title="item.name" />

        <div>
            <Button as-child variant="ghost" size="sm" class="-ml-2">
                <Link :href="inventoryUrl">
                    <ArrowLeft class="size-4" /> Back to inventory
                </Link>
            </Button>
        </div>

        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ item.name }}
                    </h1>
                    <Badge :variant="statusBadgeVariant">{{
                        stockStatus
                    }}</Badge>
                </div>
                <p
                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                >
                    <span class="font-mono">{{ item.sku }}</span>
                    <span v-if="item.category">· {{ item.category }}</span>
                    <span v-if="item.images.length">
                        · {{ item.images.length }} photo{{
                            item.images.length === 1 ? '' : 's'
                        }}
                    </span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <Link :href="warehousesUrl">
                        <WarehouseIcon class="size-4" /> Warehouses
                    </Link>
                </Button>
                <template v-if="canManage">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="openAdjustment('receive')"
                    >
                        <ArrowDownToLine class="size-4" /> Receive stock
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="item.quantity === 0"
                        @click="openAdjustment('remove')"
                    >
                        <ArrowUpFromLine class="size-4" /> Remove stock
                    </Button>
                </template>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <section
                class="flex flex-col gap-4 rounded-lg border bg-card p-4"
                aria-label="Product image"
            >
                <div
                    class="flex aspect-square items-center justify-center overflow-hidden rounded-md border bg-muted/30"
                >
                    <img
                        v-if="selectedImage"
                        :src="selectedImage.src"
                        :alt="`${item.name} image`"
                        class="size-full object-cover"
                    />
                    <Package v-else class="size-10 text-muted-foreground/60" />
                </div>
                <div
                    v-if="item.images.length > 1"
                    class="grid grid-cols-5 gap-2"
                    role="group"
                    aria-label="Product image thumbnails"
                >
                    <button
                        v-for="image in item.images"
                        :key="image.id"
                        type="button"
                        class="overflow-hidden rounded-md border bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring"
                        :class="{
                            'ring-2 ring-primary': image.id === selectedImageId,
                        }"
                        :aria-pressed="image.id === selectedImageId"
                        :aria-label="`View ${image.name}`"
                        @click="selectedImageId = image.id"
                    >
                        <img
                            :src="image.src"
                            :alt="image.name"
                            class="aspect-square size-full object-cover"
                        />
                    </button>
                </div>
                <p
                    v-if="selectedImage"
                    class="truncate text-xs text-muted-foreground"
                    :title="selectedImage.name"
                >
                    {{ selectedImage.name }}
                </p>
            </section>

            <div class="flex min-w-0 flex-col gap-6">
                <section
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Inventory summary"
                >
                    <article class="rounded-lg border bg-card p-4">
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Boxes class="size-4" /> On hand
                        </p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums">
                            {{ item.quantity.toLocaleString() }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Reorder at {{ item.reorderPoint.toLocaleString() }}
                        </p>
                    </article>
                    <article class="rounded-lg border bg-card p-4">
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Package class="size-4" /> Reorder point
                        </p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums">
                            {{ item.reorderPoint.toLocaleString() }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Units before restocking
                        </p>
                    </article>
                    <article class="rounded-lg border bg-card p-4">
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <CircleDollarSign class="size-4" /> Unit cost
                        </p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums">
                            {{ formatCurrency(item.unitCost) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Per unit
                        </p>
                    </article>
                    <article class="rounded-lg border bg-card p-4">
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <CircleDollarSign class="size-4" /> Stock value
                        </p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums">
                            {{ formatCurrency(stockValue) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            On-hand units at cost
                        </p>
                    </article>
                </section>

                <section
                    class="flex flex-col overflow-hidden rounded-lg border bg-card lg:min-h-0 lg:flex-1"
                    aria-labelledby="stock-by-warehouse-heading"
                >
                    <header
                        class="flex items-center justify-between gap-3 border-b px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <WarehouseIcon
                                class="size-4 text-muted-foreground"
                            />
                            <h2
                                id="stock-by-warehouse-heading"
                                class="text-sm font-medium"
                            >
                                Stock by warehouse
                            </h2>
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ stockByWarehouse.length }} location{{
                                stockByWarehouse.length === 1 ? '' : 's'
                            }}
                        </span>
                    </header>

                    <div
                        v-if="stockByWarehouse.length"
                        class="flex flex-col lg:flex-1"
                    >
                        <ul class="divide-y">
                            <li
                                v-for="warehouse in stockByWarehouse"
                                :key="warehouse.id"
                                class="px-4 py-3"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">
                                            {{ warehouse.name }}
                                        </p>
                                        <p
                                            v-if="warehouse.code"
                                            class="mt-0.5 font-mono text-xs text-muted-foreground"
                                        >
                                            {{ warehouse.code }}
                                        </p>
                                    </div>
                                    <span
                                        class="text-sm font-medium tabular-nums"
                                    >
                                        {{
                                            warehouse.totalUnits.toLocaleString()
                                        }}
                                        units
                                    </span>
                                </div>
                                <ul class="mt-2 space-y-1">
                                    <li
                                        v-for="entry in warehouse.locations"
                                        :key="entry.locationId"
                                        class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
                                    >
                                        <span
                                            class="flex min-w-0 items-center gap-1.5"
                                        >
                                            <MapPin class="size-3 shrink-0" />
                                            <span class="truncate">{{
                                                binLabel(entry) ||
                                                entry.locationName ||
                                                'Default bin'
                                            }}</span>
                                        </span>
                                        <span class="shrink-0 tabular-nums"
                                            >{{
                                                entry.quantity.toLocaleString()
                                            }}
                                            units</span
                                        >
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                    <div
                        v-else
                        class="px-4 py-10 text-center text-sm text-muted-foreground lg:flex lg:flex-1 lg:items-center lg:justify-center"
                    >
                        This item has no stock recorded in any warehouse yet.
                    </div>
                </section>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section
                class="overflow-hidden rounded-lg border bg-card"
                aria-labelledby="recent-movements-heading"
            >
                <header
                    class="flex items-center justify-between gap-3 border-b px-4 py-3"
                >
                    <div class="flex items-center gap-2">
                        <History class="size-4 text-muted-foreground" />
                        <h2
                            id="recent-movements-heading"
                            class="text-sm font-medium"
                        >
                            Recent movements
                        </h2>
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ movementCount }} total
                    </span>
                </header>

                <ol v-if="movements.length" class="divide-y">
                    <li
                        v-for="movement in movements"
                        :key="movement.id"
                        class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            >
                                {{ movement.typeLabel }}
                                <span
                                    class="tabular-nums"
                                    :class="
                                        movement.quantity > 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-destructive'
                                    "
                                >
                                    {{ movement.quantity > 0 ? '+' : ''
                                    }}{{ movement.quantity }}
                                </span>
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ movement.warehouseName ?? 'Warehouse' }}
                                <template v-if="movement.locationName">
                                    · {{ movement.locationName }}
                                </template>
                                · {{ movement.quantityBefore }} →
                                {{ movement.quantityAfter }}
                            </p>
                            <p
                                v-if="movement.reason"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ movement.reason }}
                            </p>
                            <p
                                v-if="movement.reference"
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                Ref {{ movement.reference }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ movement.userName }}
                            </p>
                        </div>
                        <time
                            class="shrink-0 text-xs text-muted-foreground"
                            :datetime="movement.createdAt ?? undefined"
                            >{{ formatDate(movement.createdAt) }}</time
                        >
                    </li>
                </ol>
                <div
                    v-else
                    class="px-4 py-10 text-center text-sm text-muted-foreground"
                >
                    No stock movements recorded for this item yet.
                </div>
            </section>

            <section
                class="overflow-hidden rounded-lg border bg-card"
                aria-labelledby="item-details-heading"
            >
                <header class="border-b px-4 py-3">
                    <h2 id="item-details-heading" class="text-sm font-medium">
                        Item details
                    </h2>
                </header>
                <dl class="divide-y text-sm">
                    <div
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">SKU</dt>
                        <dd class="font-mono">{{ item.sku }}</dd>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Category</dt>
                        <dd>{{ item.category || '—' }}</dd>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Status</dt>
                        <dd>{{ stockStatus }}</dd>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Added</dt>
                        <dd class="flex items-center gap-1.5 text-xs">
                            <CalendarDays class="size-3.5" />
                            {{ formatDate(item.createdAt) }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Last updated</dt>
                        <dd class="flex items-center gap-1.5 text-xs">
                            <CalendarDays class="size-3.5" />
                            {{ formatDate(item.updatedAt) }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="!canManage"
                    class="border-t px-4 py-3 text-xs text-muted-foreground"
                >
                    You have read-only access to inventory. Ask a team admin to
                    change stock or product details.
                </div>
            </section>
        </div>

        <Dialog v-model:open="adjustmentOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="adjustStock">
                    <DialogHeader>
                        <DialogTitle>{{
                            adjustmentDirection === 'receive'
                                ? 'Receive stock'
                                : 'Remove stock'
                        }}</DialogTitle>
                        <DialogDescription>
                            {{ item.name }} · Total on hand:
                            {{ item.quantity }} units
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="show-adjustment-warehouse"
                                >Warehouse</Label
                            >
                            <select
                                id="show-adjustment-warehouse"
                                v-model.number="adjustmentWarehouseId"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option
                                    v-for="warehouse in warehouses"
                                    :key="warehouse.id"
                                    :value="warehouse.id"
                                >
                                    {{ warehouse.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="show-adjustment-bin">Bin</Label>
                            <select
                                id="show-adjustment-bin"
                                v-model.number="adjustmentLocationId"
                                class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option
                                    v-for="bin in adjustmentLocations"
                                    :key="bin.id"
                                    :value="bin.id"
                                >
                                    {{ bin.name }} · {{ bin.code }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                Available in this bin:
                                <span class="tabular-nums">{{
                                    adjustmentAvailable
                                }}</span>
                                units
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="show-adjustment-amount"
                                >Units to
                                {{
                                    adjustmentDirection === 'receive'
                                        ? 'receive'
                                        : 'remove'
                                }}</Label
                            >
                            <Input
                                id="show-adjustment-amount"
                                v-model.number="adjustmentAmount"
                                type="number"
                                min="1"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="show-adjustment-reason">
                                {{
                                    adjustmentDirection === 'receive'
                                        ? 'Reference (optional)'
                                        : 'Reason'
                                }}
                            </Label>
                            <Input
                                v-if="adjustmentDirection === 'receive'"
                                id="show-adjustment-reason"
                                v-model="adjustmentReference"
                                placeholder="e.g. PO-1001, supplier"
                            />
                            <Input
                                v-else
                                id="show-adjustment-reason"
                                v-model="adjustmentReason"
                                required
                                placeholder="e.g. Damaged, stocktake correction"
                            />
                        </div>
                    </div>
                    <p
                        v-if="adjustmentError"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ adjustmentError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="adjustmentOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            :disabled="adjustmentAmount <= 0 || adjusting"
                        >
                            {{ adjusting ? 'Saving…' : 'Confirm adjustment' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
