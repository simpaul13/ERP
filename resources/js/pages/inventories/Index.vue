<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Boxes,
    ChevronLeft,
    ChevronRight,
    Download,
    History,
    Image,
    ImagePlus,
    Package,
    Pencil,
    Plus,
    Search,
    SlidersHorizontal,
    Star,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import DataTable from '@/components/ui/DataTable.vue';
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
    file?: File;
};

type InventoryItem = {
    id: number;
    sku: string;
    name: string;
    category: string;
    location: string;
    quantity: number;
    reorderPoint: number;
    unitCost: number;
    images: ProductImage[];
    mainImageId: string | null;
};

type InventoryStatus = 'all' | 'low' | 'out';

type InventoryFilters = {
    search: string;
    status: InventoryStatus;
};

type InventorySummary = {
    totalItems: number;
    totalUnits: number;
    lowStockCount: number;
    outOfStockCount: number;
    inventoryValue: number;
};

type InventoryActivity = {
    id: number;
    description: string;
    itemName: string;
    sku: string;
    userName: string;
    createdAt: string | null;
};

type PaginatedInventoryItems = {
    data: InventoryItem[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const inventoryColumns = [
    { key: 'product', label: 'Product' },
    { key: 'category', label: 'Category' },
    { key: 'location', label: 'Location' },
    { key: 'available', label: 'Available', align: 'right' as const },
    { key: 'status', label: 'Status' },
    { key: 'unitCost', label: 'Unit cost', align: 'right' as const },
    { key: 'actions', label: 'Actions', align: 'right' as const },
];

const page = usePage();

const inventoryItems = computed<PaginatedInventoryItems | undefined>(() => {
    return page.props.inventoryItems as PaginatedInventoryItems | undefined;
});
const items = computed<InventoryItem[]>(() => inventoryItems.value?.data ?? []);

const summary = computed<InventorySummary | undefined>(() => {
    return page.props.summary as InventorySummary | undefined;
});

const activities = computed<InventoryActivity[]>(() => {
    return (page.props.activities as InventoryActivity[] | undefined) ?? [];
});

const canManage = computed<boolean>(() => Boolean(page.props.canManage));

const pageFilters = page.props.filters as InventoryFilters | undefined;
const query = ref(pageFilters?.search ?? '');
const selectedStatus = ref<InventoryStatus>(pageFilters?.status ?? 'all');

const savingItem = ref(false);
const dialogOpen = ref(false);
const adjustmentOpen = ref(false);
const selectedItem = ref<InventoryItem | null>(null);
const adjustmentAmount = ref(0);
const adjustmentDirection = ref<'receive' | 'remove'>('receive');
const adjustmentReason = ref('');
const adjustmentError = ref('');
const adjusting = ref(false);
const editOpen = ref(false);
const editItem = ref<InventoryItem | null>(null);
const editError = ref('');
const savingEdit = ref(false);
const editForm = ref({
    name: '',
    sku: '',
    category: '',
    location: '',
    reorderPoint: 0,
    unitCost: 0,
});
const deleteOpen = ref(false);
const deleteItem = ref<InventoryItem | null>(null);
const deleteError = ref('');
const deleting = ref(false);
const historyOpen = ref(false);
const photoEditorOpen = ref(false);
const photoEditorItem = ref<InventoryItem | null>(null);
const photoDraft = ref<ProductImage[]>([]);
const photoDraftMainId = ref<string | null>(null);
const photoError = ref('');
const objectUrls = new Set<string>();
const newItemImages = ref<ProductImage[]>([]);
const newItemMainImageId = ref<string | null>(null);
const newItem = ref({
    name: '',
    sku: '',
    category: '',
    location: '',
    quantity: 0,
    reorderPoint: 0,
    unitCost: 0,
});

let searchTimeout: ReturnType<typeof setTimeout> | undefined;

function clearSearchTimeout(): void {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
        searchTimeout = undefined;
    }
}

onBeforeUnmount(() => {
    objectUrls.forEach((url) => URL.revokeObjectURL(url));
    clearSearchTimeout();
});

watch(query, () => {
    clearSearchTimeout();

    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

watch(selectedStatus, () => {
    applyFilters();
});

const activityDateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function currentTeamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;

    return team?.slug ?? null;
}

function applyFilters(): void {
    clearSearchTimeout();

    const slug = currentTeamSlug();

    if (!slug) {
        return;
    }

    router.get(
        `/${slug}/inventories`,
        {
            search: query.value.trim(),
            status: selectedStatus.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function pageUrl(url: string | null): string | null {
    if (!url) {
        return null;
    }

    const parsed = new URL(url, window.location.origin);

    return `${parsed.pathname}${parsed.search}`;
}

function goToPage(url: string | null): void {
    const target = pageUrl(url);

    if (!target) {
        return;
    }

    clearSearchTimeout();
    router.get(target, {}, { preserveState: true, preserveScroll: true });
}

function firstError(errors: Record<string, string>): string {
    return (
        Object.values(errors)[0] ?? 'Something went wrong. Please try again.'
    );
}

function formatActivityDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : activityDateFormatter.format(date);
}

const totalUnits = computed(() => summary.value?.totalUnits ?? 0);
const lowStockCount = computed(() => summary.value?.lowStockCount ?? 0);
const outOfStockCount = computed(() => summary.value?.outOfStockCount ?? 0);
const totalItems = computed(() => summary.value?.totalItems ?? 0);

function stockStatus(item: InventoryItem): string {
    if (item.quantity === 0) {
        return 'Out of stock';
    }

    return item.quantity <= item.reorderPoint ? 'Low stock' : 'In stock';
}

function mainImage(item: InventoryItem): ProductImage | undefined {
    return item.images.find((image) => image.id === item.mainImageId);
}

function addItem(): void {
    const slug = currentTeamSlug();

    if (
        !slug ||
        savingItem.value ||
        !newItem.value.name.trim() ||
        !newItem.value.sku.trim()
    ) {
        return;
    }

    photoError.value = '';
    savingItem.value = true;

    router.post(
        `/${slug}/inventories`,
        {
            ...newItem.value,
            name: newItem.value.name.trim(),
            sku: newItem.value.sku.trim().toUpperCase(),
            reorder_point: newItem.value.reorderPoint,
            unit_cost: newItem.value.unitCost,
            image_ids: newItemImages.value.map((image) => image.id),
            images: newItemImages.value.map((image) => image.file),
            main_image_id: newItemMainImageId.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                newItemImages.value = [];
                newItemMainImageId.value = null;
                newItem.value = {
                    name: '',
                    sku: '',
                    category: '',
                    location: '',
                    quantity: 0,
                    reorderPoint: 0,
                    unitCost: 0,
                };
                dialogOpen.value = false;
            },
            onError: (errors) => {
                photoError.value = firstError(errors);
            },
            onFinish: () => {
                savingItem.value = false;
            },
        },
    );
}

function addProductImages(event: Event, target: 'new-item' | 'editor'): void {
    const input = event.currentTarget as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    photoError.value = '';

    const images =
        target === 'new-item' ? newItemImages.value : photoDraft.value;
    const remainingSlots = 10 - images.length;
    const acceptedFiles = files.slice(0, Math.max(remainingSlots, 0));
    const rejectedFiles = files.length - acceptedFiles.length;

    acceptedFiles.forEach((file) => {
        if (!file.type.startsWith('image/')) {
            photoError.value = `${file.name} is not a supported image.`;
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            photoError.value = `${file.name} is larger than the 10 MB limit.`;
            return;
        }

        const image = {
            id: crypto.randomUUID(),
            name: file.name,
            src: URL.createObjectURL(file),
            file,
        };
        objectUrls.add(image.src);

        if (target === 'new-item') {
            newItemImages.value.push(image);
            newItemMainImageId.value ??= image.id;
        } else {
            photoDraft.value.push(image);
            photoDraftMainId.value ??= image.id;
        }
    });

    if (rejectedFiles > 0) {
        photoError.value = 'Each product can have up to 10 images.';
    }
}

function removeProductImage(
    imageId: string,
    target: 'new-item' | 'editor',
): void {
    const images = target === 'new-item' ? newItemImages : photoDraft;
    const mainImageId =
        target === 'new-item' ? newItemMainImageId : photoDraftMainId;
    const removedImage = images.value.find((image) => image.id === imageId);
    images.value = images.value.filter((image) => image.id !== imageId);

    if (removedImage?.src.startsWith('blob:')) {
        URL.revokeObjectURL(removedImage.src);
        objectUrls.delete(removedImage.src);
    }

    if (mainImageId.value === imageId) {
        mainImageId.value = images.value[0]?.id ?? null;
    }
}

function openPhotoEditor(item: InventoryItem): void {
    photoEditorItem.value = item;
    photoDraft.value = item.images.map((image) => ({ ...image }));
    photoDraftMainId.value = item.mainImageId;
    photoError.value = '';
    photoEditorOpen.value = true;
}

function savePhotoEditor(): void {
    const slug = currentTeamSlug();

    if (!photoEditorItem.value || !slug) {
        return;
    }

    const item = photoEditorItem.value;

    router.post(
        `/${slug}/inventories/${item.id}/images`,
        {
            image_ids: photoDraft.value.map((image) => image.id),
            new_image_ids: photoDraft.value
                .filter((image) => image.file)
                .map((image) => image.id),
            new_images: photoDraft.value
                .map((image) => image.file)
                .filter((file): file is File => file !== undefined),
            main_image_id: photoDraftMainId.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                photoDraft.value.forEach((image) => {
                    if (image.src.startsWith('blob:')) {
                        URL.revokeObjectURL(image.src);
                        objectUrls.delete(image.src);
                    }
                });
                photoEditorOpen.value = false;
            },
            onError: (errors) => {
                photoError.value = firstError(errors);
            },
        },
    );
}

function cancelPhotoEditor(): void {
    const savedUrls = new Set(
        (photoEditorItem.value?.images ?? []).map((image) => image.src),
    );
    photoDraft.value.forEach((image) => {
        if (image.src.startsWith('blob:') && !savedUrls.has(image.src)) {
            URL.revokeObjectURL(image.src);
            objectUrls.delete(image.src);
        }
    });
    photoEditorOpen.value = false;
}

function openAdjustment(
    item: InventoryItem,
    direction: 'receive' | 'remove',
): void {
    selectedItem.value = item;
    adjustmentDirection.value = direction;
    adjustmentAmount.value = 0;
    adjustmentReason.value = '';
    adjustmentError.value = '';
    adjustmentOpen.value = true;
}

function adjustStock(): void {
    const slug = currentTeamSlug();
    const item = selectedItem.value;

    if (!slug || !item || adjusting.value) {
        return;
    }

    const amount = Number(adjustmentAmount.value);

    if (!Number.isFinite(amount) || amount <= 0) {
        adjustmentError.value = 'Enter a quantity greater than zero.';

        return;
    }

    adjustmentError.value = '';
    adjusting.value = true;

    router.post(
        `/${slug}/inventories/${item.id}/stock`,
        {
            direction: adjustmentDirection.value,
            amount,
            reason: adjustmentReason.value.trim(),
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

function openEdit(item: InventoryItem): void {
    editItem.value = item;
    editForm.value = {
        name: item.name,
        sku: item.sku,
        category: item.category,
        location: item.location,
        reorderPoint: item.reorderPoint,
        unitCost: item.unitCost,
    };
    editError.value = '';
    editOpen.value = true;
}

function saveEdit(): void {
    const slug = currentTeamSlug();
    const item = editItem.value;

    if (!slug || !item || savingEdit.value) {
        return;
    }

    if (!editForm.value.name.trim() || !editForm.value.sku.trim()) {
        editError.value = 'Product name and SKU are required.';

        return;
    }

    editError.value = '';
    savingEdit.value = true;

    router.patch(
        `/${slug}/inventories/${item.id}`,
        {
            name: editForm.value.name.trim(),
            sku: editForm.value.sku.trim().toUpperCase(),
            category: editForm.value.category.trim(),
            location: editForm.value.location.trim(),
            reorder_point: editForm.value.reorderPoint,
            unit_cost: editForm.value.unitCost,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                editOpen.value = false;
            },
            onError: (errors) => {
                editError.value = firstError(errors);
            },
            onFinish: () => {
                savingEdit.value = false;
            },
        },
    );
}

function openDelete(item: InventoryItem): void {
    deleteItem.value = item;
    deleteError.value = '';
    deleteOpen.value = true;
}

function confirmDelete(): void {
    const slug = currentTeamSlug();
    const item = deleteItem.value;

    if (!slug || !item || deleting.value) {
        return;
    }

    deleteError.value = '';
    deleting.value = true;

    router.delete(`/${slug}/inventories/${item.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value = firstError(errors);
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

function exportInventory(): void {
    const columns = [
        'SKU',
        'Product',
        'Category',
        'Location',
        'Quantity',
        'Reorder point',
        'Unit cost',
    ];
    const rows = items.value.map((item) => [
        item.sku,
        item.name,
        item.category,
        item.location,
        item.quantity,
        item.reorderPoint,
        item.unitCost.toFixed(2),
    ]);
    const csv = [columns, ...rows]
        .map((row) =>
            row
                .map((value) => `"${String(value).replaceAll('"', '""')}"`)
                .join(','),
        )
        .join('\r\n');
    const url = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    const link = document.createElement('a');

    link.href = url;
    link.download = 'inventory.csv';
    link.click();
    URL.revokeObjectURL(url);
}

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Inventory',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/inventories`
                    : '/inventories',
            },
        ],
    }),
});
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Inventory" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Inventory</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Track products, stock levels, and warehouse locations.
                </p>
                <p v-if="!canManage" class="mt-2 text-xs text-muted-foreground">
                    You have read-only access to inventory. Ask a team admin to
                    make changes.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="historyOpen = true">
                    <History class="size-4" /> History
                </Button>
                <Button variant="outline" size="sm" @click="exportInventory">
                    <Download class="size-4" /> Export
                </Button>
                <Button v-if="canManage" size="sm" @click="dialogOpen = true">
                    <Plus class="size-4" /> Add item
                </Button>
            </div>
        </header>

        <div class="grid gap-3 sm:grid-cols-3">
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Total units</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ totalUnits.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Across {{ totalItems.toLocaleString() }} products
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Low stock</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ lowStockCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    At or below reorder point
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Out of stock</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ outOfStockCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Items with no units available
                </p>
            </section>
        </div>

        <section
            class="overflow-hidden rounded-lg border bg-card"
            aria-label="Inventory items"
        >
            <div
                class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="relative w-full sm:max-w-xs">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="query"
                        aria-label="Search inventory"
                        placeholder="Search name, SKU, location…"
                        class="pl-9"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <SlidersHorizontal class="size-4 text-muted-foreground" />
                    <label for="inventory-status" class="sr-only"
                        >Filter by stock status</label
                    >
                    <select
                        id="inventory-status"
                        v-model="selectedStatus"
                        class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="all">All items</option>
                        <option value="low">Low stock</option>
                        <option value="out">Out of stock</option>
                    </select>
                </div>
            </div>

            <!-- @vue-generic {InventoryItem} -->
            <DataTable
                :columns="inventoryColumns"
                :items="items"
                :row-key="(item) => item.id"
                accessible-label="Inventory items"
                empty-title="No inventory items found"
                empty-description="Add an item to start tracking inventory."
                min-width="850px"
            >
                <template #cell-product="{ item }">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30"
                        >
                            <img
                                v-if="mainImage(item)"
                                :src="mainImage(item)?.src"
                                :alt="`${item.name} main product image`"
                                class="size-full object-cover"
                            />
                            <Package
                                v-else
                                class="size-4 text-muted-foreground/60"
                            />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ item.name }}</p>
                            <p
                                class="mt-0.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ item.sku }}
                                <span
                                    v-if="item.images.length"
                                    class="font-sans"
                                >
                                    · {{ item.images.length }} photos
                                </span>
                            </p>
                        </div>
                    </div>
                </template>
                <template #cell-category="{ item }">
                    <span class="text-muted-foreground">{{
                        item.category || '—'
                    }}</span>
                </template>
                <template #cell-location="{ item }">
                    <span class="text-muted-foreground">{{
                        item.location || '—'
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
                            :class="{
                                'bg-foreground':
                                    stockStatus(item) === 'In stock',
                                'bg-amber-500':
                                    stockStatus(item) === 'Low stock',
                                'bg-muted-foreground':
                                    stockStatus(item) === 'Out of stock',
                            }"
                        />
                        {{ stockStatus(item) }}
                    </span>
                </template>
                <template #cell-unitCost="{ item }">
                    <span class="tabular-nums"
                        >${{ item.unitCost.toFixed(2) }}</span
                    >
                </template>
                <template #cell-actions="{ item }">
                    <div v-if="canManage" class="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :aria-label="`Manage product images for ${item.name}`"
                            @click="openPhotoEditor(item)"
                        >
                            <Image class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :aria-label="`Receive stock for ${item.name}`"
                            @click="openAdjustment(item, 'receive')"
                        >
                            <ArrowDownToLine class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :aria-label="`Remove stock for ${item.name}`"
                            :disabled="item.quantity === 0"
                            @click="openAdjustment(item, 'remove')"
                        >
                            <ArrowUpFromLine class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :aria-label="`Edit ${item.name}`"
                            @click="openEdit(item)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8 text-muted-foreground hover:text-destructive"
                            :aria-label="`Delete ${item.name}`"
                            @click="openDelete(item)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <span v-else class="text-xs text-muted-foreground"
                        >Read only</span
                    >
                </template>
                <template #empty>
                    <Boxes class="mx-auto size-8 text-muted-foreground" />
                    <p class="mt-3 font-medium">No inventory items found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add an item to start tracking inventory.
                    </p>
                </template>
            </DataTable>
            <footer
                class="flex flex-col gap-3 border-t px-4 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    Showing {{ inventoryItems?.from ?? 0 }} to
                    {{ inventoryItems?.to ?? 0 }} of
                    {{ inventoryItems?.total ?? 0 }} items
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!inventoryItems?.prev_page_url"
                        @click="goToPage(inventoryItems?.prev_page_url ?? null)"
                    >
                        <ChevronLeft class="size-4" /> Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!inventoryItems?.next_page_url"
                        @click="goToPage(inventoryItems?.next_page_url ?? null)"
                    >
                        Next <ChevronRight class="size-4" />
                    </Button>
                </div>
            </footer>
        </section>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="addItem">
                    <DialogHeader>
                        <DialogTitle>Add inventory item</DialogTitle>
                        <DialogDescription
                            >Create a product record for this inventory
                            view.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="item-name">Product name</Label
                            ><Input
                                id="item-name"
                                v-model="newItem.name"
                                required
                                placeholder="e.g. Shipping carton · Large"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-sku">SKU</Label
                            ><Input
                                id="item-sku"
                                v-model="newItem.sku"
                                required
                                placeholder="e.g. BX-2048"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-category">Category</Label
                            ><Input
                                id="item-category"
                                v-model="newItem.category"
                                placeholder="Packaging"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-location">Location</Label
                            ><Input
                                id="item-location"
                                v-model="newItem.location"
                                placeholder="North warehouse"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-quantity">Available units</Label
                            ><Input
                                id="item-quantity"
                                v-model.number="newItem.quantity"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-reorder">Reorder point</Label
                            ><Input
                                id="item-reorder"
                                v-model.number="newItem.reorderPoint"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-cost">Unit cost</Label
                            ><Input
                                id="item-cost"
                                v-model.number="newItem.unitCost"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                        </div>
                    </div>
                    <section
                        class="space-y-3 border-t pt-4"
                        aria-labelledby="new-item-images-heading"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3
                                    id="new-item-images-heading"
                                    class="text-sm font-medium"
                                >
                                    Product images
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Add up to 10 photos. The main image appears
                                    in your inventory list.
                                </p>
                            </div>
                            <label
                                for="new-item-image-upload"
                                class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-2 rounded-md border bg-background px-3 text-xs font-medium focus-within:ring-2 focus-within:ring-ring hover:bg-muted/40"
                            >
                                <ImagePlus class="size-4" /> Add photos
                            </label>
                            <input
                                id="new-item-image-upload"
                                type="file"
                                accept="image/*"
                                multiple
                                class="sr-only"
                                @change="addProductImages($event, 'new-item')"
                            />
                        </div>
                        <div
                            v-if="newItemImages.length"
                            class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                        >
                            <article
                                v-for="image in newItemImages"
                                :key="image.id"
                                class="overflow-hidden rounded-md border bg-card"
                            >
                                <div class="relative aspect-[4/3] bg-muted/30">
                                    <img
                                        :src="image.src"
                                        :alt="image.name"
                                        class="size-full object-cover"
                                    />
                                    <button
                                        type="button"
                                        class="absolute top-2 right-2 inline-flex size-7 items-center justify-center rounded-full bg-background/90 text-muted-foreground shadow-sm hover:text-destructive"
                                        :aria-label="`Remove ${image.name}`"
                                        @click="
                                            removeProductImage(
                                                image.id,
                                                'new-item',
                                            )
                                        "
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </div>
                                <div
                                    class="flex min-h-10 items-center justify-between gap-2 px-2.5"
                                >
                                    <span
                                        class="min-w-0 truncate text-xs text-muted-foreground"
                                        >{{ image.name }}</span
                                    >
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1 text-xs font-medium"
                                        :class="
                                            newItemMainImageId === image.id
                                                ? 'text-primary'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        :aria-pressed="
                                            newItemMainImageId === image.id
                                        "
                                        @click="newItemMainImageId = image.id"
                                    >
                                        <Star
                                            class="size-3.5"
                                            :class="{
                                                'fill-current':
                                                    newItemMainImageId ===
                                                    image.id,
                                            }"
                                        />
                                        {{
                                            newItemMainImageId === image.id
                                                ? 'Main'
                                                : 'Set main'
                                        }}
                                    </button>
                                </div>
                            </article>
                        </div>
                        <p
                            v-if="photoError"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ photoError }}
                        </p>
                        <div
                            v-if="newItemImages.length === 0"
                            class="rounded-md border border-dashed bg-muted/20 px-4 py-5 text-center text-xs text-muted-foreground"
                        >
                            Choose one or more product photos. Your first photo
                            is selected as the main image automatically.
                        </div>
                    </section>
                    <DialogFooter
                        ><Button type="submit" :disabled="savingItem">
                            {{ savingItem ? 'Saving…' : 'Add item' }}
                        </Button></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="editOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="saveEdit">
                    <DialogHeader>
                        <DialogTitle>Edit inventory item</DialogTitle>
                        <DialogDescription>
                            Update {{ editItem?.name }} · Quantity is changed
                            with stock adjustments.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="edit-item-name">Product name</Label>
                            <Input
                                id="edit-item-name"
                                v-model="editForm.name"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-item-sku">SKU</Label>
                            <Input
                                id="edit-item-sku"
                                v-model="editForm.sku"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-item-category">Category</Label>
                            <Input
                                id="edit-item-category"
                                v-model="editForm.category"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-item-location">Location</Label>
                            <Input
                                id="edit-item-location"
                                v-model="editForm.location"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-item-reorder">Reorder point</Label>
                            <Input
                                id="edit-item-reorder"
                                v-model.number="editForm.reorderPoint"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-item-cost">Unit cost</Label>
                            <Input
                                id="edit-item-cost"
                                v-model.number="editForm.unitCost"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                        </div>
                    </div>
                    <p
                        v-if="editError"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ editError }}
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="editOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="savingEdit">
                            {{ savingEdit ? 'Saving…' : 'Save changes' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete inventory item</DialogTitle>
                    <DialogDescription>
                        This permanently deletes
                        <strong>{{ deleteItem?.name }}</strong>
                        ({{ deleteItem?.sku }}) and its product images. This
                        action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="deleteError"
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ deleteError }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="deleteOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="deleting"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete item' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="historyOpen">
            <DialogContent
                class="max-h-[min(90vh,680px)] overflow-y-auto sm:max-w-lg"
            >
                <DialogHeader>
                    <DialogTitle>Inventory activity</DialogTitle>
                    <DialogDescription>
                        Recent stock and product changes for this team.
                    </DialogDescription>
                </DialogHeader>
                <ol v-if="activities.length" class="divide-y">
                    <li
                        v-for="activity in activities"
                        :key="activity.id"
                        class="py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ activity.description }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ activity.itemName }}
                                    <span class="font-mono"
                                        >· {{ activity.sku }}</span
                                    >
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ activity.userName }}
                                </p>
                            </div>
                            <time
                                class="shrink-0 text-xs text-muted-foreground"
                                :datetime="activity.createdAt ?? undefined"
                                >{{
                                    formatActivityDate(activity.createdAt)
                                }}</time
                            >
                        </div>
                    </li>
                </ol>
                <div
                    v-else
                    class="rounded-md border border-dashed px-4 py-8 text-center"
                >
                    <History class="mx-auto size-6 text-muted-foreground" />
                    <p class="mt-2 text-sm font-medium">No activity yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Stock adjustments and product changes will appear here.
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="historyOpen = false"
                        >Close</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="photoEditorOpen">
            <DialogContent
                class="max-h-[min(90vh,760px)] overflow-y-auto sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle>Product images</DialogTitle>
                    <DialogDescription>
                        {{ photoEditorItem?.name }} · Upload up to 10 photos and
                        choose which one represents this product.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4">
                    <label
                        for="product-image-upload"
                        class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/20 px-5 py-6 text-center transition-colors focus-within:ring-2 focus-within:ring-ring hover:bg-muted/40"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full border bg-background"
                        >
                            <Upload class="size-4 text-muted-foreground" />
                        </span>
                        <span class="text-sm font-medium"
                            >Choose product photos</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >Select a single image or multiple images · 10 MB
                            max each · 10 images per product</span
                        >
                    </label>
                    <input
                        id="product-image-upload"
                        type="file"
                        accept="image/*"
                        multiple
                        class="sr-only"
                        @change="addProductImages($event, 'editor')"
                    />

                    <p
                        v-if="photoError"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ photoError }}
                    </p>

                    <div
                        v-if="photoDraft.length"
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        <article
                            v-for="image in photoDraft"
                            :key="image.id"
                            class="overflow-hidden rounded-md border bg-card"
                        >
                            <div class="relative aspect-[4/3] bg-muted/30">
                                <img
                                    :src="image.src"
                                    :alt="image.name"
                                    class="size-full object-cover"
                                />
                                <button
                                    type="button"
                                    class="absolute top-2 right-2 inline-flex size-8 items-center justify-center rounded-full bg-background/90 text-muted-foreground shadow-sm hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="`Remove ${image.name}`"
                                    @click="
                                        removeProductImage(image.id, 'editor')
                                    "
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                            <div
                                class="flex min-h-11 items-center justify-between gap-2 px-2.5"
                            >
                                <span
                                    class="min-w-0 truncate text-xs text-muted-foreground"
                                    :title="image.name"
                                    >{{ image.name }}</span
                                >
                                <button
                                    type="button"
                                    class="inline-flex min-h-8 shrink-0 items-center gap-1 text-xs font-medium"
                                    :class="
                                        photoDraftMainId === image.id
                                            ? 'text-primary'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    :aria-pressed="
                                        photoDraftMainId === image.id
                                    "
                                    @click="photoDraftMainId = image.id"
                                >
                                    <Star
                                        class="size-3.5"
                                        :class="{
                                            'fill-current':
                                                photoDraftMainId === image.id,
                                        }"
                                    />
                                    {{
                                        photoDraftMainId === image.id
                                            ? 'Main image'
                                            : 'Set as main'
                                    }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="rounded-md border border-dashed px-4 py-8 text-center"
                    >
                        <ImagePlus
                            class="mx-auto size-6 text-muted-foreground"
                        />
                        <p class="mt-2 text-sm font-medium">
                            No product photos yet
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Add images to show a main product photo in your
                            inventory list.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="cancelPhotoEditor"
                        >Cancel</Button
                    >
                    <Button type="button" @click="savePhotoEditor"
                        >Save images</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="adjustmentOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="adjustStock">
                    <DialogHeader>
                        <DialogTitle>{{
                            adjustmentDirection === 'receive'
                                ? 'Receive stock'
                                : 'Remove stock'
                        }}</DialogTitle>
                        <DialogDescription
                            >{{ selectedItem?.name }} · Current quantity:
                            {{ selectedItem?.quantity ?? 0 }}</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="adjustment-amount"
                            >Units to
                            {{
                                adjustmentDirection === 'receive'
                                    ? 'receive'
                                    : 'remove'
                            }}</Label
                        ><Input
                            id="adjustment-amount"
                            v-model.number="adjustmentAmount"
                            type="number"
                            min="1"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="adjustment-reason">Reason (optional)</Label>
                        <Input
                            id="adjustment-reason"
                            v-model="adjustmentReason"
                            placeholder="e.g. Supplier delivery, damaged units"
                        />
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
