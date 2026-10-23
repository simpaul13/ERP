<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    MapPin,
    Pencil,
    Plus,
    Trash2,
    Warehouse as WarehouseIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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
const warehouseForm = ref({
    name: '',
    code: '',
    address: '',
    isDefault: false,
});
const savingWarehouse = ref(false);
const warehouseError = ref('');

const deleteDialogOpen = ref(false);
const deletingWarehouse = ref<WarehouseRecord | null>(null);
const removingWarehouse = ref(false);
const warehouseDeleteError = ref('');

const binDialogOpen = ref(false);
const binWarehouse = ref<WarehouseRecord | null>(null);
const editingBin = ref<WarehouseBin | null>(null);
const binForm = ref({ name: '', code: '' });
const savingBin = ref(false);
const binError = ref('');

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

function openCreateWarehouse(): void {
    editingWarehouse.value = null;
    warehouseForm.value = {
        name: '',
        code: '',
        address: '',
        isDefault: props.warehouses.length === 0,
    };
    warehouseError.value = '';
    warehouseDialogOpen.value = true;
}

function openEditWarehouse(warehouse: WarehouseRecord): void {
    editingWarehouse.value = warehouse;
    warehouseForm.value = {
        name: warehouse.name,
        code: warehouse.code,
        address: warehouse.address,
        isDefault: warehouse.isDefault,
    };
    warehouseError.value = '';
    warehouseDialogOpen.value = true;
}

function saveWarehouse(): void {
    const slug = currentTeamSlug();

    if (!slug || savingWarehouse.value || !warehouseForm.value.name.trim()) {
        return;
    }

    const url = editingWarehouse.value
        ? `/${slug}/warehouses/${editingWarehouse.value.id}`
        : `/${slug}/warehouses`;

    warehouseError.value = '';
    savingWarehouse.value = true;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            warehouseDialogOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            warehouseError.value = firstError(errors);
        },
        onFinish: () => {
            savingWarehouse.value = false;
        },
    };

    const payload = {
        name: warehouseForm.value.name.trim(),
        code: warehouseForm.value.code.trim(),
        address: warehouseForm.value.address.trim(),
        is_default: warehouseForm.value.isDefault,
    };

    if (editingWarehouse.value) {
        router.patch(url, payload, options);
    } else {
        router.post(url, payload, options);
    }
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
    binForm.value = { name: '', code: '' };
    binError.value = '';
    binDialogOpen.value = true;
}

function openEditBin(warehouse: WarehouseRecord, bin: WarehouseBin): void {
    binWarehouse.value = warehouse;
    editingBin.value = bin;
    binForm.value = { name: bin.name, code: bin.code };
    binError.value = '';
    binDialogOpen.value = true;
}

function saveBin(): void {
    const slug = currentTeamSlug();
    const warehouse = binWarehouse.value;

    if (!slug || !warehouse || savingBin.value || !binForm.value.name.trim()) {
        return;
    }

    const url = editingBin.value
        ? `/${slug}/warehouses/${warehouse.id}/locations/${editingBin.value.id}`
        : `/${slug}/warehouses/${warehouse.id}/locations`;

    binError.value = '';
    savingBin.value = true;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            binDialogOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            binError.value = firstError(errors);
        },
        onFinish: () => {
            savingBin.value = false;
        },
    };

    const payload = {
        name: binForm.value.name.trim(),
        code: binForm.value.code.trim(),
    };

    if (editingBin.value) {
        router.patch(url, payload, options);
    } else {
        router.post(url, payload, options);
    }
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

    router.delete(
        `/${slug}/warehouses/${warehouse.id}/locations/${bin.id}`,
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
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Warehouses" />

        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Warehouses</h1>
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
                                        {{ warehouse.name }}
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
                        <div
                            v-if="canManage"
                            class="flex shrink-0 items-center gap-1"
                        >
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
                    <div
                        v-if="canManage"
                        class="flex justify-end px-4 py-2.5"
                    >
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

        <Dialog v-model:open="warehouseDialogOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="saveWarehouse">
                    <DialogHeader>
                        <DialogTitle>{{
                            editingWarehouse ? 'Edit warehouse' : 'Add warehouse'
                        }}</DialogTitle>
                        <DialogDescription>
                            A warehouse is a physical place that holds stock,
                            such as a store room or distribution centre.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="warehouse-name">Name</Label>
                            <Input
                                id="warehouse-name"
                                v-model="warehouseForm.name"
                                required
                                placeholder="e.g. North warehouse"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="warehouse-code">Code</Label>
                            <Input
                                id="warehouse-code"
                                v-model="warehouseForm.code"
                                placeholder="Auto-generated if left blank"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="warehouse-address">Address</Label>
                            <Input
                                id="warehouse-address"
                                v-model="warehouseForm.address"
                                placeholder="Optional"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm"
                            for="warehouse-default"
                        >
                            <input
                                id="warehouse-default"
                                v-model="warehouseForm.isDefault"
                                type="checkbox"
                                class="size-4 rounded border"
                            />
                            Use as the team's default warehouse
                        </label>
                    </div>
                    <p
                        v-if="warehouseError"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ warehouseError }}
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="warehouseDialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="savingWarehouse">
                            {{
                                savingWarehouse
                                    ? 'Saving…'
                                    : editingWarehouse
                                      ? 'Save changes'
                                      : 'Add warehouse'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="binDialogOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="saveBin">
                    <DialogHeader>
                        <DialogTitle>{{
                            editingBin ? 'Edit bin' : 'Add bin'
                        }}</DialogTitle>
                        <DialogDescription>
                            {{ binWarehouse?.name }} · Bins let you pinpoint
                            where an item is stored inside the warehouse.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="bin-name">Name</Label>
                            <Input
                                id="bin-name"
                                v-model="binForm.name"
                                required
                                placeholder="e.g. Aisle A · Shelf 2"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="bin-code">Code</Label>
                            <Input
                                id="bin-code"
                                v-model="binForm.code"
                                placeholder="Auto-generated if left blank"
                            />
                        </div>
                    </div>
                    <p
                        v-if="binError"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ binError }}
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="binDialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="savingBin">
                            {{
                                savingBin
                                    ? 'Saving…'
                                    : editingBin
                                      ? 'Save changes'
                                      : 'Add bin'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Archive warehouse</DialogTitle>
                    <DialogDescription>
                        Archive <strong>{{ deletingWarehouse?.name }}</strong
                        >? It will no longer appear in inventory screens. A
                        warehouse can only be archived when it holds no stock.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="warehouseDeleteError"
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ warehouseDeleteError }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="deleteDialogOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="removingWarehouse"
                        @click="confirmDeleteWarehouse"
                    >
                        {{ removingWarehouse ? 'Archiving…' : 'Archive' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteBinOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove bin</DialogTitle>
                    <DialogDescription>
                        Remove <strong>{{ deletingBin?.name }}</strong
                        >? A bin can only be removed when it holds no stock.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="binDeleteError"
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ binDeleteError }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="deleteBinOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="removingBin"
                        @click="confirmDeleteBin"
                    >
                        {{ removingBin ? 'Removing…' : 'Remove bin' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </main>
</template>
