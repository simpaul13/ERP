<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Boxes,
    ChevronLeft,
    ChevronRight,
    History,
    MapPin,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
    Warehouse as WarehouseIcon,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BinDialog from '@/components/inventory/BinDialog.vue';
import ConfirmActionDialog from '@/components/inventory/ConfirmActionDialog.vue';
import WarehouseDialog from '@/components/inventory/WarehouseDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DataTable from '@/components/ui/DataTable.vue';
import { Input } from '@/components/ui/input';

type WarehouseProfile = {
    id: number;
    code: string;
    name: string;
    address: string;
    isDefault: boolean;
    createdAt: string | null;
};

type WarehouseBin = {
    id: number;
    code: string;
    name: string;
    isDefault: boolean;
    totalUnits: number;
    stockedItems: number;
};

type StockedItemBin = {
    id: number | null;
    name: string | null;
    code: string | null;
    quantity: number;
};

type StockedItem = {
    id: number;
    sku: string;
    name: string;
    category: string;
    quantity: number;
    reorderPoint: number;
    unitCost: number;
    value: number;
    status: 'in' | 'low';
    mainImageSrc: string | null;
    bins: StockedItemBin[];
};

type WarehouseMovement = {
    id: number;
    type: string;
    typeLabel: string;
    itemName: string;
    sku: string;
    locationName: string | null;
    quantity: number;
    quantityAfter: number;
    unitCost: number;
    reference: string | null;
    reason: string | null;
    userName: string;
    createdAt: string | null;
};

type WarehouseSummary = {
    totalUnits: number;
    totalBins: number;
    stockedItems: number;
    lowStockItems: number;
    inventoryValue: number;
};

type PaginatedStockedItems = {
    data: StockedItem[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type PaginatedWarehouseMovements = {
    data: WarehouseMovement[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    warehouse: WarehouseProfile;
    summary: WarehouseSummary;
    bins: WarehouseBin[];
    items: PaginatedStockedItems;
    productsSearch: string;
    movements: PaginatedWarehouseMovements;
    movementsSearch: string;
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
            {
                title: 'Warehouses',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/warehouses`
                    : '/warehouses',
            },
            { title: 'Profile', href: '#' },
        ],
    }),
});

const itemColumns = [
    { key: 'product', label: 'Product' },
    { key: 'bins', label: 'Bins' },
    { key: 'available', label: 'Available', align: 'right' as const },
    { key: 'status', label: 'Status' },
    { key: 'value', label: 'Value', align: 'right' as const },
];

const movementColumns = [
    { key: 'date', label: 'Date' },
    { key: 'item', label: 'Item' },
    { key: 'type', label: 'Movement' },
    { key: 'change', label: 'Change', align: 'right' as const },
    { key: 'bin', label: 'Bin' },
    { key: 'user', label: 'By' },
];

const page = usePage();

const editDialogOpen = ref(false);
const archiveDialogOpen = ref(false);
const archiving = ref(false);
const archiveError = ref('');
const productSearch = ref(props.productsSearch);
let productSearchTimeout: ReturnType<typeof setTimeout> | undefined;
const movementSearch = ref(props.movementsSearch);
let movementSearchTimeout: ReturnType<typeof setTimeout> | undefined;

function updateSearchQuery(
    searchParameter: string,
    pageParameter: string,
    search: string,
): void {
    const currentUrl = new URL(window.location.href);
    const trimmedSearch = search.trim();

    if (trimmedSearch) {
        currentUrl.searchParams.set(searchParameter, trimmedSearch);
    } else {
        currentUrl.searchParams.delete(searchParameter);
    }

    currentUrl.searchParams.delete(pageParameter);

    const query = currentUrl.searchParams.toString();
    const target = query
        ? `${currentUrl.pathname}?${query}`
        : currentUrl.pathname;

    router.get(
        target,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(productSearch, () => {
    if (productSearchTimeout) {
        clearTimeout(productSearchTimeout);
    }

    productSearchTimeout = setTimeout(() => {
        updateSearchQuery('products_search', 'page', productSearch.value);
    }, 300);
});

watch(movementSearch, () => {
    if (movementSearchTimeout) {
        clearTimeout(movementSearchTimeout);
    }

    movementSearchTimeout = setTimeout(() => {
        updateSearchQuery(
            'movements_search',
            'movements_page',
            movementSearch.value,
        );
    }, 300);
});

watch(
    () => props.productsSearch,
    (search) => {
        productSearch.value = search;
    },
);

watch(
    () => props.movementsSearch,
    (search) => {
        movementSearch.value = search;
    },
);

onBeforeUnmount(() => {
    if (productSearchTimeout) {
        clearTimeout(productSearchTimeout);
    }

    if (movementSearchTimeout) {
        clearTimeout(movementSearchTimeout);
    }
});

const binDialogOpen = ref(false);
const editingBin = ref<WarehouseBin | null>(null);

const deleteBinOpen = ref(false);
const deletingBin = ref<WarehouseBin | null>(null);
const removingBin = ref(false);
const binDeleteError = ref('');

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
});

const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

const warehousesUrl = computed<string>(() => {
    const slug = currentTeamSlug();

    return slug ? `/${slug}/warehouses` : '/warehouses';
});

const inventoryUrl = computed<string>(() => {
    const slug = currentTeamSlug();

    return slug
        ? `/${slug}/inventories?warehouse=${props.warehouse.id}`
        : '/inventories';
});

function currentTeamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;

    return team?.slug ?? null;
}

function firstError(errors: Record<string, string>): string {
    return (
        Object.values(errors)[0] ?? 'Something went wrong. Please try again.'
    );
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : dateTimeFormatter.format(date);
}

function statusLabel(item: StockedItem): string {
    return item.status === 'low' ? 'Low stock' : 'In stock';
}

function binsLabel(item: StockedItem): string {
    if (!item.bins.length) {
        return '—';
    }

    return item.bins
        .map((bin) => `${bin.name ?? bin.code ?? 'Bin'} · ${bin.quantity}`)
        .join(' · ');
}

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    const parsed = new URL(url, window.location.origin);

    router.get(
        `${parsed.pathname}${parsed.search}`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function openEditBin(bin: WarehouseBin): void {
    editingBin.value = bin;
    binDialogOpen.value = true;
}

function openCreateBin(): void {
    editingBin.value = null;
    binDialogOpen.value = true;
}

function openDeleteBin(bin: WarehouseBin): void {
    deletingBin.value = bin;
    binDeleteError.value = '';
    deleteBinOpen.value = true;
}

function confirmDeleteBin(): void {
    const slug = currentTeamSlug();
    const bin = deletingBin.value;

    if (!slug || !bin || removingBin.value) {
        return;
    }

    binDeleteError.value = '';
    removingBin.value = true;

    router.delete(
        `/${slug}/warehouses/${props.warehouse.id}/locations/${bin.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                deleteBinOpen.value = false;
            },
            onError: (errors: Record<string, string>) => {
                binDeleteError.value = firstError(errors);
            },
            onFinish: () => {
                removingBin.value = false;
            },
        },
    );
}

function confirmArchive(): void {
    const slug = currentTeamSlug();

    if (!slug || archiving.value) {
        return;
    }

    archiveError.value = '';
    archiving.value = true;

    router.delete(`/${slug}/warehouses/${props.warehouse.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            archiveDialogOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            archiveError.value = firstError(errors);
        },
        onFinish: () => {
            archiving.value = false;
        },
    });
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head :title="warehouse.name" />

        <div>
            <Button as-child variant="ghost" size="sm" class="-ml-2">
                <Link :href="warehousesUrl">
                    <ArrowLeft class="size-4" /> Back to warehouses
                </Link>
            </Button>
        </div>

        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
        >
            <div class="flex min-w-0 items-start gap-4">
                <span
                    class="flex size-14 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                >
                    <WarehouseIcon class="size-6 text-muted-foreground" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1
                            class="truncate text-2xl font-semibold tracking-tight"
                        >
                            {{ warehouse.name }}
                        </h1>
                        <Badge v-if="warehouse.isDefault" variant="secondary"
                            >Default</Badge
                        >
                    </div>
                    <p class="mt-1 font-mono text-xs text-muted-foreground">
                        {{ warehouse.code }}
                    </p>
                    <p
                        v-if="warehouse.address"
                        class="mt-1 flex items-center gap-1 text-sm text-muted-foreground"
                    >
                        <MapPin class="size-3.5" />
                        {{ warehouse.address }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Added {{ formatDate(warehouse.createdAt) }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-start gap-2">
                <Button
                    v-if="canManage"
                    variant="outline"
                    size="sm"
                    @click="editDialogOpen = true"
                >
                    <Pencil class="size-4" /> Edit
                </Button>
                <Button
                    v-if="canManage"
                    variant="outline"
                    size="sm"
                    @click="openCreateBin"
                >
                    <Plus class="size-4" /> Add bin
                </Button>
                <Button
                    v-if="canManage"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground hover:text-destructive"
                    @click="archiveDialogOpen = true"
                >
                    <Trash2 class="size-4" /> Archive
                </Button>
            </div>
        </header>

        <p v-if="!canManage" class="text-xs text-muted-foreground">
            You have read-only access to this warehouse. Ask a team admin to
            make changes.
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Units on hand</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ summary.totalUnits.toLocaleString() }}
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Products stocked</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ summary.stockedItems }}
                </p>
                <p
                    v-if="summary.lowStockItems"
                    class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                >
                    {{ summary.lowStockItems }} at or below reorder point
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Bins</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ summary.totalBins }}
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Stock value</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ currencyFormatter.format(summary.inventoryValue) }}
                </p>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="overflow-hidden rounded-lg border bg-card">
                <header
                    class="flex items-start justify-between gap-3 border-b p-4"
                >
                    <div>
                        <h2 class="font-medium">Bins</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Storage positions inside this warehouse.
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        variant="outline"
                        size="sm"
                        @click="openCreateBin"
                    >
                        <Plus class="size-3.5" /> Add
                    </Button>
                </header>
                <div v-if="bins.length" class="divide-y">
                    <article
                        v-for="bin in bins"
                        :key="bin.id"
                        class="flex items-start justify-between gap-3 p-4"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted/30"
                            >
                                <Boxes class="size-4 text-muted-foreground" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-sm font-medium">
                                        {{ bin.name }}
                                    </p>
                                    <Badge
                                        v-if="bin.isDefault"
                                        variant="outline"
                                        class="text-[10px]"
                                        >Default</Badge
                                    >
                                </div>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ bin.code }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ bin.totalUnits.toLocaleString() }} units
                                    · {{ bin.stockedItems }} products
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="
                                canManage && (!bin.isDefault || bins.length > 1)
                            "
                            class="flex shrink-0 items-center gap-1"
                        >
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Edit ${bin.name}`"
                                @click="openEditBin(bin)"
                            >
                                <Pencil class="size-3.5" />
                            </Button>
                            <Button
                                v-if="!bin.isDefault && bins.length > 1"
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove ${bin.name}`"
                                @click="openDeleteBin(bin)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </article>
                </div>
                <div
                    v-else
                    class="p-6 text-center text-sm text-muted-foreground"
                >
                    No bins yet.
                </div>
            </section>

            <section
                class="overflow-hidden rounded-lg border bg-card lg:col-span-2"
            >
                <header
                    class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <h2 class="font-medium">Products in this warehouse</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Stock currently held across the bins above.
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="relative w-full sm:max-w-xs">
                            <Search
                                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="productSearch"
                                aria-label="Search warehouse products"
                                placeholder="Search name, SKU, category…"
                                class="pl-9"
                            />
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="inventoryUrl">Open inventory</Link>
                        </Button>
                    </div>
                </header>
                <!-- @vue-generic {StockedItem} -->
                <DataTable
                    :columns="itemColumns"
                    :items="items.data"
                    :row-key="(item) => item.id"
                    accessible-label="Products stocked in this warehouse"
                    empty-title="No products stocked"
                    empty-description="Receive stock into this warehouse to see products here."
                    min-width="680px"
                >
                    <template #cell-product="{ item }">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30"
                            >
                                <img
                                    v-if="item.mainImageSrc"
                                    :src="item.mainImageSrc"
                                    :alt="`${item.name} product image`"
                                    class="size-full object-cover"
                                />
                                <Package
                                    v-else
                                    class="size-4 text-muted-foreground/60"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ item.name }}
                                </p>
                                <p
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ item.sku }}
                                    <span
                                        v-if="item.category"
                                        class="font-sans"
                                    >
                                        · {{ item.category }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </template>
                    <template #cell-bins="{ item }">
                        <span class="text-xs text-muted-foreground">{{
                            binsLabel(item)
                        }}</span>
                    </template>
                    <template #cell-available="{ item }">
                        <span class="tabular-nums">{{ item.quantity }}</span>
                        <span class="text-xs text-muted-foreground"
                            >/ min {{ item.reorderPoint }}</span
                        >
                    </template>
                    <template #cell-status="{ item }">
                        <span class="inline-flex items-center gap-2 text-xs">
                            <span
                                class="size-2 rounded-full"
                                :class="
                                    item.status === 'low'
                                        ? 'bg-amber-500'
                                        : 'bg-foreground'
                                "
                            />
                            {{ statusLabel(item) }}
                        </span>
                    </template>
                    <template #cell-value="{ item }">
                        <span class="tabular-nums">{{
                            currencyFormatter.format(item.value)
                        }}</span>
                    </template>
                    <template #empty>
                        <Package class="mx-auto size-8 text-muted-foreground" />
                        <p class="mt-3 font-medium">No products stocked</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Receive stock into this warehouse to see products
                            here.
                        </p>
                    </template>
                </DataTable>
                <footer
                    class="flex flex-col gap-3 border-t px-4 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
                >
                    <p>
                        Showing {{ items.from ?? 0 }} to {{ items.to ?? 0 }} of
                        {{ items.total }} products
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!items.prev_page_url"
                            @click="goToPage(items.prev_page_url)"
                        >
                            <ChevronLeft class="size-4" /> Previous
                        </Button>
                        <span class="px-1">
                            Page {{ items.current_page }} of
                            {{ items.last_page }}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!items.next_page_url"
                            @click="goToPage(items.next_page_url)"
                        >
                            Next <ChevronRight class="size-4" />
                        </Button>
                    </div>
                </footer>
            </section>
        </div>

        <section class="overflow-hidden rounded-lg border bg-card">
            <header
                class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="font-medium">Recent stock movements</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        The latest activity recorded in this warehouse.
                    </p>
                </div>
                <div class="relative w-full sm:max-w-xs">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="movementSearch"
                        aria-label="Search movements"
                        placeholder="Search item, SKU, reason…"
                        class="pl-9"
                    />
                </div>
            </header>
            <!-- @vue-generic {WarehouseMovement} -->
            <DataTable
                :columns="movementColumns"
                :items="movements.data"
                :row-key="(movement) => movement.id"
                accessible-label="Recent stock movements"
                empty-title="No stock movements yet"
                empty-description="Movements appear here when stock is received or adjusted."
                min-width="760px"
            >
                <template #cell-date="{ item }">
                    <span
                        class="text-xs whitespace-nowrap text-muted-foreground"
                    >
                        {{ formatDateTime(item.createdAt) }}
                    </span>
                </template>
                <template #cell-item="{ item }">
                    <div class="min-w-0">
                        <p class="truncate text-sm">{{ item.itemName }}</p>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ item.sku }}
                        </p>
                    </div>
                </template>
                <template #cell-type="{ item }">
                    <Badge variant="outline">{{ item.typeLabel }}</Badge>
                    <p
                        v-if="item.reason"
                        class="mt-1 max-w-56 truncate text-xs text-muted-foreground"
                    >
                        {{ item.reason }}
                    </p>
                </template>
                <template #cell-change="{ item }">
                    <span
                        class="font-medium tabular-nums"
                        :class="
                            item.quantity > 0
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-destructive'
                        "
                    >
                        {{ item.quantity > 0 ? '+' : '' }}{{ item.quantity }}
                    </span>
                    <p class="text-xs text-muted-foreground">
                        now {{ item.quantityAfter }}
                    </p>
                </template>
                <template #cell-bin="{ item }">
                    <span class="text-sm text-muted-foreground">{{
                        item.locationName ?? '—'
                    }}</span>
                </template>
                <template #cell-user="{ item }">
                    <span class="text-sm text-muted-foreground">{{
                        item.userName
                    }}</span>
                </template>
                <template #empty>
                    <History class="mx-auto size-8 text-muted-foreground" />
                    <p class="mt-3 font-medium">No stock movements yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Movements appear here when stock is received or
                        adjusted.
                    </p>
                </template>
            </DataTable>
            <footer
                class="flex flex-col gap-3 border-t px-4 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    Showing {{ movements.from ?? 0 }} to
                    {{ movements.to ?? 0 }} of {{ movements.total }} movements
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!movements.prev_page_url"
                        @click="goToPage(movements.prev_page_url)"
                    >
                        <ChevronLeft class="size-4" /> Previous
                    </Button>
                    <span class="px-1">
                        Page {{ movements.current_page }} of
                        {{ movements.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!movements.next_page_url"
                        @click="goToPage(movements.next_page_url)"
                    >
                        Next <ChevronRight class="size-4" />
                    </Button>
                </div>
            </footer>
        </section>

        <WarehouseDialog v-model:open="editDialogOpen" :warehouse="warehouse" />

        <BinDialog
            v-model:open="binDialogOpen"
            :warehouse-id="warehouse.id"
            :warehouse-name="warehouse.name"
            :bin="editingBin"
        />

        <ConfirmActionDialog
            v-model:open="archiveDialogOpen"
            title="Archive warehouse"
            confirm-label="Archive"
            processing-label="Archiving…"
            :processing="archiving"
            :error="archiveError"
            @confirm="confirmArchive"
        >
            Archive <strong>{{ warehouse.name }}</strong
            >? It will no longer appear in inventory screens. A warehouse can
            only be archived when it holds no stock.
        </ConfirmActionDialog>

        <ConfirmActionDialog
            v-model:open="deleteBinOpen"
            title="Remove bin"
            confirm-label="Remove bin"
            processing-label="Removing…"
            :processing="removingBin"
            :error="binDeleteError"
            @confirm="confirmDeleteBin"
        >
            Remove <strong>{{ deletingBin?.name }}</strong
            >? A bin can only be removed when it holds no stock.
        </ConfirmActionDialog>
    </main>
</template>
