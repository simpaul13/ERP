<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Eye,
    MapPin,
    Pencil,
    Plus,
    Trash2,
    Warehouse as WarehouseIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BinDialog from '@/components/inventory/BinDialog.vue';
import ConfirmActionDialog from '@/components/inventory/ConfirmActionDialog.vue';
import WarehouseDialog from '@/components/inventory/WarehouseDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type WarehouseBin = {
    id: number;
    code: string;
    name: string;
    isDefault: boolean;
    totalUnits: number;
};

type WarehouseRecord = {
    id: number;
    code: string;
    name: string;
    address: string;
    isDefault: boolean;
    totalUnits: number;
    locations: WarehouseBin[];
};

const props = defineProps<{
    warehouses: WarehouseRecord[];
    canManage: boolean;
    defaultWarehouseId: number | null;
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
            { title: 'Warehouses', href: '#' },
        ],
    }),
});

const page = usePage();

const warehouseDialogOpen = ref(false);
const editingWarehouse = ref<WarehouseRecord | null>(null);

const deleteDialogOpen = ref(false);
const deletingWarehouse = ref<WarehouseRecord | null>(null);
const removingWarehouse = ref(false);
const warehouseDeleteError = ref('');

const binDialogOpen = ref(false);
const binWarehouse = ref<WarehouseRecord | null>(null);
const editingBin = ref<WarehouseBin | null>(null);

const deleteBinOpen = ref(false);
const deletingBin = ref<WarehouseBin | null>(null);
const removingBin = ref(false);
const binDeleteError = ref('');

const totalUnits = computed(() =>
    props.warehouses.reduce((sum, warehouse) => sum + warehouse.totalUnits, 0),
);

const totalBins = computed(() =>
    props.warehouses.reduce(
        (sum, warehouse) => sum + warehouse.locations.length,
        0,
    ),
);

function currentTeamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;

    return team?.slug ?? null;
}

function firstError(errors: Record<string, string>): string {
    return (
        Object.values(errors)[0] ?? 'Something went wrong. Please try again.'
    );
}

function warehouseUrl(warehouse: WarehouseRecord): string {
    const slug = currentTeamSlug();

    return slug ? `/${slug}/warehouses/${warehouse.id}` : '#';
}

function openCreateWarehouse(): void {
    editingWarehouse.value = null;
    warehouseDialogOpen.value = true;
}

function openEditWarehouse(warehouse: WarehouseRecord): void {
    editingWarehouse.value = warehouse;
    warehouseDialogOpen.value = true;
}

function openDeleteWarehouse(warehouse: WarehouseRecord): void {
    deletingWarehouse.value = warehouse;
    warehouseDeleteError.value = '';
    deleteDialogOpen.value = true;
}

function confirmDeleteWarehouse(): void {
    const slug = currentTeamSlug();

    if (!slug || !deletingWarehouse.value || removingWarehouse.value) {
        return;
    }

    warehouseDeleteError.value = '';
    removingWarehouse.value = true;

    router.delete(`/${slug}/warehouses/${deletingWarehouse.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            warehouseDeleteError.value = firstError(errors);
        },
        onFinish: () => {
            removingWarehouse.value = false;
        },
    });
}

function openCreateBin(warehouse: WarehouseRecord): void {
    binWarehouse.value = warehouse;
    editingBin.value = null;
    binDialogOpen.value = true;
}

function openEditBin(warehouse: WarehouseRecord, bin: WarehouseBin): void {
    binWarehouse.value = warehouse;
    editingBin.value = bin;
    binDialogOpen.value = true;
}

function openDeleteBin(warehouse: WarehouseRecord, bin: WarehouseBin): void {
    binWarehouse.value = warehouse;
    deletingBin.value = bin;
    binDeleteError.value = '';
    deleteBinOpen.value = true;
}

function confirmDeleteBin(): void {
    const slug = currentTeamSlug();
    const warehouse = binWarehouse.value;
    const bin = deletingBin.value;

    if (!slug || !warehouse || !bin || removingBin.value) {
        return;
    }

    binDeleteError.value = '';
    removingBin.value = true;

    router.delete(`/${slug}/warehouses/${warehouse.id}/locations/${bin.id}`, {
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
    });
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Warehouses" />

        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Warehouses
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Warehouses are the places that hold your stock. Add bins
                    inside a warehouse to track exactly where an item sits.
                </p>
                <p v-if="!canManage" class="mt-2 text-xs text-muted-foreground">
                    You have read-only access. Ask a team admin to add or change
                    warehouses.
                </p>
            </div>
            <Button v-if="canManage" size="sm" @click="openCreateWarehouse">
                <Plus class="size-4" /> Add warehouse
            </Button>
        </header>

        <div class="grid gap-3 sm:grid-cols-3">
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Warehouses</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ warehouses.length }}
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Bins</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ totalBins }}
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Units on hand</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ totalUnits.toLocaleString() }}
                </p>
            </section>
        </div>

        <div v-if="warehouses.length" class="grid gap-4 xl:grid-cols-2">
            <article
                v-for="warehouse in warehouses"
                :key="warehouse.id"
                class="overflow-hidden rounded-lg border bg-card"
            >
                <header class="border-b p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted/30"
                            >
                                <WarehouseIcon
                                    class="size-4 text-muted-foreground"
                                />
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate font-medium">
                                        <Link
                                            :href="warehouseUrl(warehouse)"
                                            class="hover:underline"
                                        >
                                            {{ warehouse.name }}
                                        </Link>
                                    </h2>
                                    <Badge
                                        v-if="warehouse.isDefault"
                                        variant="secondary"
                                        >Default</Badge
                                    >
                                </div>
                                <p
                                    class="mt-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ warehouse.code }}
                                </p>
                                <p
                                    v-if="warehouse.address"
                                    class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <MapPin class="size-3" />
                                    {{ warehouse.address }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`View ${warehouse.name}`"
                            >
                                <Link :href="warehouseUrl(warehouse)">
                                    <Eye class="size-4" />
                                </Link>
                            </Button>
                            <template v-if="canManage">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit ${warehouse.name}`"
                                    @click="openEditWarehouse(warehouse)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Archive ${warehouse.name}`"
                                    @click="openDeleteWarehouse(warehouse)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </template>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Boxes class="size-3.5" />
                        {{ warehouse.totalUnits.toLocaleString() }} units across
                        {{ warehouse.locations.length }} bins
                    </div>
                </header>

                <div class="divide-y">
                    <div
                        v-for="bin in warehouse.locations"
                        :key="bin.id"
                        class="flex items-center justify-between gap-3 px-4 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm">
                                {{ bin.name }}
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                    >· {{ bin.code }}</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ bin.totalUnits.toLocaleString() }} units
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <Badge
                                v-if="bin.isDefault"
                                variant="outline"
                                class="text-[10px]"
                                >Default</Badge
                            >
                            <template
                                v-if="
                                    canManage &&
                                    !bin.isDefault &&
                                    warehouse.locations.length > 1
                                "
                            >
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit ${bin.name}`"
                                    @click="openEditBin(warehouse, bin)"
                                >
                                    <Pencil class="size-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Remove ${bin.name}`"
                                    @click="openDeleteBin(warehouse, bin)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </template>
                        </div>
                    </div>
                    <div v-if="canManage" class="flex justify-end px-4 py-2.5">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="openCreateBin(warehouse)"
                        >
                            <Plus class="size-3.5" /> Add bin
                        </Button>
                    </div>
                </div>
            </article>
        </div>

        <div
            v-else
            class="rounded-lg border border-dashed px-6 py-12 text-center"
        >
            <WarehouseIcon class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 font-medium">No warehouses yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Add your first warehouse to start tracking where stock lives.
            </p>
            <Button v-if="canManage" class="mt-4" @click="openCreateWarehouse">
                <Plus class="size-4" /> Add warehouse
            </Button>
        </div>

        <WarehouseDialog
            v-model:open="warehouseDialogOpen"
            :warehouse="editingWarehouse"
            :has-warehouses="warehouses.length > 0"
        />

        <BinDialog
            v-model:open="binDialogOpen"
            :warehouse-id="binWarehouse?.id ?? null"
            :warehouse-name="binWarehouse?.name"
            :bin="editingBin"
        />

        <ConfirmActionDialog
            v-model:open="deleteDialogOpen"
            title="Archive warehouse"
            confirm-label="Archive"
            processing-label="Archiving…"
            :processing="removingWarehouse"
            :error="warehouseDeleteError"
            @confirm="confirmDeleteWarehouse"
        >
            Archive <strong>{{ deletingWarehouse?.name }}</strong
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
