<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Barcode, Boxes, RotateCcw } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WarehouseOption = {
    id: number;
    name: string;
    locations: { id: number; name: string; code: string }[];
};
type Workflow = 'receiving' | 'picking' | 'cycle_count';
type ScanResult = {
    scanId: string;
    itemCode: string;
    binCode: string;
    quantity: number;
    quantityAfter: number | null;
    duplicate: boolean;
    message: string;
};

const props = defineProps<{
    warehouses: WarehouseOption[];
    canScan: boolean;
}>();

const page = usePage();
const workflow = ref<Workflow>('receiving');
const warehouseId = ref(props.warehouses[0]?.id ?? 0);
const itemCode = ref('');
const binCode = ref('');
const binSuggestionsOpen = ref(false);
const quantity = ref(1);
const transactionId = ref('');
const scans = ref<ScanResult[]>([]);
const feedback = ref(
    'Scan an item, then scan its bin to record the warehouse action.',
);
const error = ref('');
const scanning = ref(false);
const canRecordScan = computed(
    () =>
        Boolean(teamSlug()) &&
        currentWarehouse.value !== undefined &&
        itemCode.value.trim().length > 0 &&
        binCode.value.trim().length > 0 &&
        String(quantity.value).trim().length > 0 &&
        Number.isFinite(Number(quantity.value)) &&
        Number(quantity.value) >= (workflow.value === 'cycle_count' ? 0 : 1) &&
        Number(quantity.value) <= 1000000,
);

const currentWarehouse = computed(() =>
    props.warehouses.find((warehouse) => warehouse.id === warehouseId.value),
);
const matchingBins = computed(() => {
    const search = binCode.value.trim().toLocaleLowerCase();

    return (currentWarehouse.value?.locations ?? []).filter(
        (location) =>
            location.code.toLocaleLowerCase().includes(search) ||
            location.name.toLocaleLowerCase().includes(search),
    );
});

onMounted(() => {
    transactionId.value = crypto.randomUUID();
});

watch(warehouseId, () => {
    binCode.value = '';
    binSuggestionsOpen.value = false;
});

function selectBin(code: string): void {
    binCode.value = code;
    binSuggestionsOpen.value = false;
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
            { title: 'Barcode scanning', href: '#' },
        ],
    }),
});

function teamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;
    return team?.slug ?? null;
}

function beginTransaction(): void {
    transactionId.value = crypto.randomUUID();
    scans.value = [];
    feedback.value = 'New scan transaction started.';
    error.value = '';
}

function xsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return cookie ? decodeURIComponent(cookie) : '';
}

function advanceFromItem(): void {
    document.getElementById('scan-bin')?.focus();
}

function advanceFromBin(): void {
    if (workflow.value === 'cycle_count') {
        document.getElementById('scan-quantity')?.focus();
        return;
    }

    void recordScan();
}

async function recordScan(): Promise<void> {
    const slug = teamSlug();
    if (
        !slug ||
        scanning.value ||
        !itemCode.value.trim() ||
        !binCode.value.trim()
    ) {
        return;
    }

    scanning.value = true;
    error.value = '';

    try {
        const response = await fetch(`/${slug}/inventory-scans`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                workflow: workflow.value,
                transaction_id: transactionId.value,
                scan_id: crypto.randomUUID(),
                item_code: itemCode.value.trim(),
                bin_code: binCode.value.trim(),
                warehouse_id: warehouseId.value,
                quantity: Number(quantity.value),
            }),
        });
        const payload = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
            scanId?: string;
            itemCode?: string;
            binCode?: string;
            quantity?: number;
            quantityAfter?: number | null;
            duplicate?: boolean;
        };

        if (!response.ok) {
            error.value =
                Object.values(payload.errors ?? {})[0]?.[0] ??
                payload.message ??
                'The scan could not be recorded. Check the item and bin codes.';
            return;
        }

        if (payload.scanId && payload.itemCode && payload.binCode) {
            scans.value.unshift({
                scanId: payload.scanId,
                itemCode: payload.itemCode,
                binCode: payload.binCode,
                quantity: payload.quantity ?? Number(quantity.value),
                quantityAfter: payload.quantityAfter ?? null,
                duplicate: payload.duplicate ?? false,
                message: payload.message ?? 'Scan recorded successfully.',
            });
        }
        feedback.value = payload.message ?? 'Scan recorded successfully.';
        itemCode.value = '';
        binCode.value = '';
        quantity.value = workflow.value === 'cycle_count' ? 0 : 1;
        document.getElementById('scan-item')?.focus();
    } catch {
        error.value =
            'Unable to reach the server. The scan has not been confirmed.';
    } finally {
        scanning.value = false;
    }
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Barcode scanning" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Warehouse scanning
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Scan or enter the item SKU and bin code. Keyboard-wedge
                    barcode scanners work like a keyboard.
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link
                    :href="
                        teamSlug()
                            ? `/${teamSlug()}/inventories`
                            : '/inventories'
                    "
                >
                    <Boxes class="size-4" /> Back to inventory
                </Link>
            </Button>
        </header>

        <section
            v-if="!canScan"
            class="rounded-lg border p-5 text-sm text-muted-foreground"
        >
            You have read-only access to inventory and cannot record warehouse
            scans.
        </section>

        <section
            v-else
            class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
        >
            <form
                class="grid content-start gap-5 rounded-lg border bg-card p-5"
                @submit.prevent="recordScan"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-medium">Scan transaction</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Recording a scan can change stock. Confirm the task,
                            warehouse, item, bin, and quantity first.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="beginTransaction"
                    >
                        <RotateCcw class="size-4" /> New transaction
                    </Button>
                </div>

                <div class="grid gap-2">
                    <Label for="scan-warehouse">Warehouse</Label>
                    <select
                        id="scan-warehouse"
                        v-model.number="warehouseId"
                        required
                        class="h-9 rounded-md border bg-background px-3 text-sm"
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

                <div class="grid gap-2">
                    <Label for="scan-workflow">Warehouse task</Label>
                    <select
                        id="scan-workflow"
                        v-model="workflow"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="receiving">Receiving · add stock</option>
                        <option value="picking">Picking · remove stock</option>
                        <option value="cycle_count">
                            Cycle count · set counted quantity
                        </option>
                    </select>
                </div>

                <div class="grid gap-2">
                    <Label for="scan-item">Item barcode / SKU</Label>
                    <Input
                        id="scan-item"
                        v-model="itemCode"
                        autofocus
                        autocomplete="off"
                        placeholder="Scan or enter item SKU"
                        required
                        @keydown.enter.prevent="advanceFromItem"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="scan-bin">Bin barcode / code</Label>
                    <Input
                        id="scan-bin"
                        v-model="binCode"
                        autocomplete="off"
                        placeholder="Choose or scan a bin code"
                        required
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="warehouse-bin-options"
                        :aria-expanded="binSuggestionsOpen"
                        @focus="binSuggestionsOpen = true"
                        @click="binSuggestionsOpen = true"
                        @blur="binSuggestionsOpen = false"
                        @keydown.enter.prevent="advanceFromBin"
                    />
                    <ul
                        v-if="binSuggestionsOpen && matchingBins.length"
                        id="warehouse-bin-options"
                        role="listbox"
                        aria-label="Available bins"
                        class="max-h-48 overflow-y-auto rounded-md border bg-popover p-1 shadow-sm"
                    >
                        <button
                            v-for="location in matchingBins"
                            :key="location.id"
                            type="button"
                            role="option"
                            aria-selected="false"
                            class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-sm px-3 py-2 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            @mousedown.prevent
                            @click="selectBin(location.code)"
                        >
                            <span>{{ location.name }}</span>
                            <span class="text-muted-foreground">{{
                                location.code
                            }}</span>
                        </button>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Choose a listed bin, or scan/type its code. {{
                            currentWarehouse?.locations.length ?? 0
                        }}
                        bins available in
                        {{ currentWarehouse?.name ?? 'this warehouse' }}.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="scan-quantity">{{
                        workflow === 'cycle_count'
                            ? 'Counted quantity'
                            : 'Quantity'
                    }}</Label>
                    <Input
                        id="scan-quantity"
                        v-model.number="quantity"
                        type="number"
                        :min="workflow === 'cycle_count' ? 0 : 1"
                        max="1000000"
                        required
                        @keydown.enter.prevent="recordScan"
                    />
                    <p
                        v-if="workflow === 'cycle_count'"
                        class="text-xs text-muted-foreground"
                    >
                        Enter the total physical quantity counted in this bin,
                        including zero.
                    </p>
                </div>

                <p
                    v-if="error"
                    role="alert"
                    class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <p
                    v-else
                    role="status"
                    class="rounded-md border bg-muted/30 p-3 text-sm"
                >
                    {{ feedback }}
                </p>
                <Button type="submit" :disabled="scanning || !canRecordScan">
                    <Barcode class="size-4" />
                    {{ scanning ? 'Recording scan…' : 'Record scan' }}
                </Button>
            </form>

            <section class="overflow-hidden rounded-lg border bg-card">
                <header class="border-b p-4">
                    <h2 class="font-medium">Transaction activity</h2>
                    <p
                        class="mt-1 font-mono text-xs break-all text-muted-foreground"
                    >
                        {{ transactionId }}
                    </p>
                </header>
                <ol v-if="scans.length" class="divide-y">
                    <li v-for="scan in scans" :key="scan.scanId" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">
                                    {{ scan.itemCode }}
                                    <span class="text-muted-foreground"
                                        >→ {{ scan.binCode }}</span
                                    >
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ scan.message }}
                                </p>
                            </div>
                            <span class="shrink-0 text-sm tabular-nums"
                                >{{
                                    workflow === 'cycle_count' ? 'Count' : 'Qty'
                                }}
                                {{ scan.quantity }}</span
                            >
                        </div>
                        <p
                            v-if="scan.quantityAfter !== null"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Bin balance: {{ scan.quantityAfter }}
                        </p>
                    </li>
                </ol>
                <div
                    v-else
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    <Barcode class="mx-auto size-8" />
                    <p class="mt-3">
                        Scans in this transaction will appear here.
                    </p>
                </div>
            </section>
        </section>
    </main>
</template>
