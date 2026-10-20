<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Boxes,
    Download,
    Image,
    ImagePlus,
    Package,
    Plus,
    Search,
    SlidersHorizontal,
    Star,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
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
};

type InventoryItem = {
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

const inventoryColumns = [
    { key: 'product', label: 'Product' },
    { key: 'category', label: 'Category' },
    { key: 'location', label: 'Location' },
    { key: 'available', label: 'Available', align: 'right' as const },
    { key: 'status', label: 'Status' },
    { key: 'unitCost', label: 'Unit cost', align: 'right' as const },
    { key: 'actions', label: 'Actions', align: 'right' as const },
];

const items = ref<InventoryItem[]>([]);

const query = ref('');
const selectedStatus = ref('All items');
const dialogOpen = ref(false);
const adjustmentOpen = ref(false);
const selectedItem = ref<InventoryItem | null>(null);
const adjustmentAmount = ref(0);
const adjustmentDirection = ref<'receive' | 'remove'>('receive');
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

onBeforeUnmount(() => {
    objectUrls.forEach((url) => URL.revokeObjectURL(url));
});

const filteredItems = computed(() =>
    items.value.filter((item) => {
        const matchesQuery =
            `${item.name} ${item.sku} ${item.category} ${item.location}`
                .toLowerCase()
                .includes(query.value.toLowerCase());
        const matchesStatus =
            selectedStatus.value === 'All items' ||
            (selectedStatus.value === 'Low stock' &&
                item.quantity > 0 &&
                item.quantity <= item.reorderPoint) ||
            (selectedStatus.value === 'Out of stock' && item.quantity === 0);

        return matchesQuery && matchesStatus;
    }),
);

const totalUnits = computed(() =>
    items.value.reduce((total, item) => total + item.quantity, 0),
);
const lowStockCount = computed(
    () =>
        items.value.filter(
            (item) => item.quantity > 0 && item.quantity <= item.reorderPoint,
        ).length,
);
const outOfStockCount = computed(
    () => items.value.filter((item) => item.quantity === 0).length,
);

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
    if (!newItem.value.name.trim() || !newItem.value.sku.trim()) {
        return;
    }

    items.value.unshift({
        ...newItem.value,
        sku: newItem.value.sku.trim().toUpperCase(),
        images: [...newItemImages.value],
        mainImageId: newItemMainImageId.value,
    });
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
    if (!photoEditorItem.value) {
        return;
    }

    const retainedUrls = new Set(photoDraft.value.map((image) => image.src));
    photoEditorItem.value.images.forEach((image) => {
        if (image.src.startsWith('blob:') && !retainedUrls.has(image.src)) {
            URL.revokeObjectURL(image.src);
            objectUrls.delete(image.src);
        }
    });

    photoEditorItem.value.images = photoDraft.value.map((image) => ({
        ...image,
    }));
    photoEditorItem.value.mainImageId = photoDraftMainId.value;
    photoEditorOpen.value = false;
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
    adjustmentOpen.value = true;
}

function adjustStock(): void {
    if (!selectedItem.value || adjustmentAmount.value <= 0) {
        return;
    }

    selectedItem.value.quantity =
        adjustmentDirection.value === 'receive'
            ? selectedItem.value.quantity + Number(adjustmentAmount.value)
            : Math.max(
                  0,
                  selectedItem.value.quantity - Number(adjustmentAmount.value),
              );
    adjustmentOpen.value = false;
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
    <Head title="Inventory" />

    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Inventory</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Track products, stock levels, and warehouse locations.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="exportInventory">
                    <Download class="size-4" /> Export
                </Button>
                <Button size="sm" @click="dialogOpen = true">
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
                    Across {{ items.length }} products
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
                        <option>All items</option>
                        <option>Low stock</option>
                        <option>Out of stock</option>
                    </select>
                </div>
            </div>

            <!-- @vue-generic {InventoryItem} -->
            <DataTable
                :columns="inventoryColumns"
                :items="filteredItems"
                :row-key="(item) => item.sku"
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
                    <div class="flex justify-end gap-1">
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
                    </div>
                </template>
                <template #empty>
                    <Boxes class="mx-auto size-8 text-muted-foreground" />
                    <p class="mt-3 font-medium">No inventory items found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add an item to start tracking inventory.
                    </p>
                </template>
            </DataTable>
            <footer class="border-t px-4 py-3 text-xs text-muted-foreground">
                Showing {{ filteredItems.length }} of {{ items.length }} items
            </footer>
        </section>
    </main>

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
                                Add up to 10 photos. The main image appears in
                                your inventory list.
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
                                        removeProductImage(image.id, 'new-item')
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
                                                newItemMainImageId === image.id,
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
                        Choose one or more product photos. Your first photo is
                        selected as the main image automatically.
                    </div>
                </section>
                <DialogFooter
                    ><Button type="submit">Add item</Button></DialogFooter
                >
            </form>
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
                        >Select a single image or multiple images · 10 MB max
                        each · 10 images per product</span
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
                                @click="removeProductImage(image.id, 'editor')"
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
                                :aria-pressed="photoDraftMainId === image.id"
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
                    <ImagePlus class="mx-auto size-6 text-muted-foreground" />
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
                <DialogFooter
                    ><Button type="submit" :disabled="adjustmentAmount <= 0"
                        >Confirm adjustment</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
