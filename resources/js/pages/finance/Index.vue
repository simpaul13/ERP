<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Pencil, Plus, Search, Tag, Trash2, Wallet } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import DataTable from '@/components/ui/DataTable.vue';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Category = { id: number; name: string; type: string; isActive: boolean };
type Transaction = {
    id: number;
    date: string;
    description: string;
    type: string;
    amount: string;
    paymentMethod: string;
    categoryId: number;
    category: string;
};
const props = defineProps<{
    transactions: Transaction[];
    categories: Category[];
    totals: { income: string; expenses: string };
    filters: {
        from: string;
        to: string;
        type: string;
        category: string | number;
        search: string;
    };
    canManage: boolean;
}>();
const page = usePage();
const filters = ref({ ...props.filters });
const formOpen = ref(false);
const editing = ref<Transaction | null>(null);
const categoryEditing = ref<Category | null>(null);
const categoryOpen = ref(false);
const formError = ref('');
const form = ref({
    amount: '',
    transaction_date: new Date().toISOString().slice(0, 10),
    description: '',
    type: 'income',
    payment_method: 'cash',
    finance_category_id: '',
});
const categoryForm = ref({ name: '', type: 'income', is_active: true });
const net = computed(
    () => Number(props.totals.income) - Number(props.totals.expenses),
);
const availableCategories = computed(() =>
    props.categories.filter(
        (category) =>
            category.type === form.value.type &&
            (category.isActive ||
                category.id === Number(form.value.finance_category_id)),
    ),
);
const transactionColumns = computed(() => [
    {
        key: 'date',
        label: 'Date',
        class: 'whitespace-nowrap text-muted-foreground',
    },
    { key: 'description', label: 'Transaction', class: 'max-w-xs' },
    { key: 'type', label: 'Type' },
    { key: 'paymentMethod', label: 'Payment' },
    {
        key: 'amount',
        label: 'Amount',
        align: 'right' as const,
        class: 'whitespace-nowrap font-medium tabular-nums',
    },
    ...(props.canManage
        ? [{ key: 'actions', label: 'Actions', align: 'right' as const }]
        : []),
]);

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Finance',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/finance`
                    : '/finance',
            },
        ],
    }),
});
function url(path: string): string {
    const team = (page.props.currentTeam as { slug: string } | null)?.slug;
    return `/${team}${path}`;
}
function applyFilters(): void {
    router.get(url('/finance'), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
}
function openCreate(): void {
    editing.value = null;
    form.value = {
        amount: '',
        transaction_date: new Date().toISOString().slice(0, 10),
        description: '',
        type: 'income',
        payment_method: 'cash',
        finance_category_id: '',
    };
    formOpen.value = true;
}
function openEdit(transaction: Transaction): void {
    editing.value = transaction;
    form.value = {
        amount: transaction.amount,
        transaction_date: transaction.date,
        description: transaction.description,
        type: transaction.type,
        payment_method: transaction.paymentMethod,
        finance_category_id: String(transaction.categoryId),
    };
    formOpen.value = true;
}
function saveTransaction(): void {
    formError.value = '';
    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) =>
            (formError.value =
                Object.values(errors)[0] ?? 'Unable to save transaction.'),
        onSuccess: () => (formOpen.value = false),
    };
    if (editing.value) {
        router.patch(
            url(`/finance/transactions/${editing.value.id}`),
            form.value,
            options,
        );
    } else {
        router.post(url('/finance/transactions'), form.value, options);
    }
}
function deleteTransaction(transaction: Transaction): void {
    if (window.confirm(`Delete “${transaction.description}”?`))
        router.delete(url(`/finance/transactions/${transaction.id}`), {
            preserveScroll: true,
        });
}
function openCategory(category?: Category): void {
    categoryEditing.value = category ?? null;
    categoryForm.value = category
        ? {
              name: category.name,
              type: category.type,
              is_active: category.isActive,
          }
        : { name: '', type: 'income', is_active: true };
    formError.value = '';
    categoryOpen.value = true;
}
function saveCategory(): void {
    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) =>
            (formError.value =
                Object.values(errors)[0] ?? 'Unable to save category.'),
        onSuccess: () => (categoryOpen.value = false),
    };
    if (categoryEditing.value) {
        router.patch(
            url(`/finance/categories/${categoryEditing.value.id}`),
            categoryForm.value,
            options,
        );
    } else {
        router.post(url('/finance/categories'), categoryForm.value, options);
    }
}
function money(value: string | number): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Finance" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Finance</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Track money received and spent by your team.
                </p>
            </div>
            <Button v-if="canManage" size="sm" @click="openCreate">
                <Plus data-icon="inline-start" /> Record transaction
            </Button>
        </header>

        <section
            aria-label="Finance overview"
            class="grid gap-3 sm:grid-cols-3"
        >
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Income</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ money(totals.income) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    For the selected date range
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Expenses</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ money(totals.expenses) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    For the selected date range
                </p>
            </section>
            <section class="rounded-lg border bg-card p-4">
                <p class="text-sm text-muted-foreground">Net</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ money(net) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Income less expenses
                </p>
            </section>
        </section>

        <section
            aria-label="Transactions"
            class="overflow-hidden rounded-lg border bg-card"
        >
            <form
                role="search"
                class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between"
                @submit.prevent="applyFilters"
            >
                <div class="relative w-full sm:max-w-xs">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filters.search"
                        type="search"
                        aria-label="Search transactions"
                        placeholder="Search transactions…"
                        class="pl-9"
                    />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label for="finance-from" class="sr-only">From date</label>
                    <input
                        id="finance-from"
                        v-model="filters.from"
                        type="date"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <label for="finance-to" class="sr-only">To date</label>
                    <input
                        id="finance-to"
                        v-model="filters.to"
                        type="date"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <label for="finance-type" class="sr-only">Type</label>
                    <select
                        id="finance-type"
                        v-model="filters.type"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">All types</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    <label for="finance-category-filter" class="sr-only"
                        >Category</label
                    >
                    <select
                        id="finance-category-filter"
                        v-model="filters.category"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">All categories</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                    <Button type="submit" size="sm"> Apply </Button>
                </div>
            </form>
            <DataTable
                :columns="transactionColumns"
                :items="transactions"
                :row-key="(transaction) => transaction.id"
                accessible-label="Finance transactions"
                empty-title="No transactions found"
                empty-description="Try adjusting your filters or record a transaction to get started."
                min-width="720px"
            >
                <template #cell-description="{ item }">
                    <p class="truncate font-medium">{{ item.description }}</p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ item.category }}
                    </p>
                </template>
                <template #cell-type="{ item }">
                    <span
                        class="inline-flex items-center gap-1.5 text-xs font-medium capitalize"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                item.type === 'income'
                                    ? 'bg-primary'
                                    : 'bg-muted-foreground'
                            "
                        />
                        {{ item.type }}
                    </span>
                </template>
                <template #cell-paymentMethod="{ item }">
                    <span class="text-muted-foreground capitalize">{{
                        item.paymentMethod
                    }}</span>
                </template>
                <template #cell-amount="{ item }">
                    {{ item.type === 'income' ? '+' : '−'
                    }}{{ money(item.amount) }}
                </template>
                <template v-if="canManage" #cell-actions="{ item }">
                    <div class="inline-flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="`Edit ${item.description}`"
                            @click="openEdit(item)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="`Delete ${item.description}`"
                            class="text-destructive hover:text-destructive"
                            @click="deleteTransaction(item)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </template>
                <template #empty>
                    <Wallet
                        class="mx-auto size-8 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="mt-3 font-medium">No transactions found</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Try adjusting your filters or record a transaction to
                        get started.
                    </p>
                    <Button
                        v-if="canManage"
                        class="mt-4"
                        size="sm"
                        @click="openCreate"
                        ><Plus data-icon="inline-start" /> Record
                        transaction</Button
                    >
                </template>
            </DataTable>
        </section>
        <section class="rounded-lg border bg-card p-4">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <Tag
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h2 class="font-semibold">Categories</h2>
                </div>
                <Button
                    v-if="canManage"
                    size="sm"
                    variant="outline"
                    @click="openCategory()"
                    >Add category</Button
                >
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <div
                    v-for="category in categories"
                    :key="category.id"
                    class="flex items-center justify-between gap-3 rounded-md border px-3 py-2.5 text-sm"
                >
                    <span class="min-w-0">
                        <span class="font-medium">{{ category.name }}</span>
                        <span class="text-muted-foreground">
                            · {{ category.type }}</span
                        >
                        <span
                            v-if="!category.isActive"
                            class="text-muted-foreground"
                        >
                            · Inactive</span
                        >
                    </span>
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="ghost"
                        @click="openCategory(category)"
                        >Edit</Button
                    >
                </div>
            </div>
        </section>
        <Dialog v-model:open="formOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form class="grid gap-5" @submit.prevent="saveTransaction">
                    <DialogHeader class="text-left">
                        <DialogTitle>{{
                            editing ? 'Edit transaction' : 'Record transaction'
                        }}</DialogTitle>
                        <DialogDescription
                            >Enter the transaction details below. Required
                            fields are marked by the form.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm"
                            >Type<select
                                v-model="form.type"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            >
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select></label
                        ><label class="grid gap-1 text-sm"
                            >Amount<input
                                v-model="form.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50" /></label
                        ><label class="grid gap-1 text-sm"
                            >Date<input
                                v-model="form.transaction_date"
                                type="date"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50" /></label
                        ><label class="grid gap-1 text-sm"
                            >Category<select
                                v-model="form.finance_category_id"
                                required
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            >
                                <option value="">Choose category</option>
                                <option
                                    v-for="category in availableCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }}
                                </option>
                            </select></label
                        ><label class="grid gap-1 text-sm"
                            >Payment method<select
                                v-model="form.payment_method"
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            >
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                            </select></label
                        ><label class="grid gap-1 text-sm sm:col-span-2"
                            >Description<input
                                v-model="form.description"
                                required
                                maxlength="255"
                                class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        /></label>
                    </div>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="formOpen = false"
                            >Cancel</Button
                        ><Button type="submit">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="categoryOpen">
            <DialogContent class="sm:max-w-md">
                <form class="grid gap-5" @submit.prevent="saveCategory">
                    <DialogHeader class="text-left">
                        <DialogTitle>{{
                            categoryEditing ? 'Edit category' : 'Add category'
                        }}</DialogTitle>
                        <DialogDescription
                            >Organize transactions by income or expense
                            category.</DialogDescription
                        >
                    </DialogHeader>
                    <label class="grid gap-1 text-sm"
                        >Name<input
                            v-model="categoryForm.name"
                            required
                            maxlength="100"
                            class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50" /></label
                    ><label class="grid gap-1 text-sm"
                        >Type<select
                            v-model="categoryForm.type"
                            class="h-9 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        >
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select></label
                    ><label
                        v-if="categoryEditing"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="categoryForm.is_active"
                            type="checkbox"
                        />Active</label
                    >
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="
                                categoryOpen = false;
                                formError = '';
                            "
                            >Cancel</Button
                        ><Button type="submit">Save category</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
