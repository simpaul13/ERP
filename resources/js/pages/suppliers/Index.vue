<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Truck } from '@lucide/vue';
import { ref } from 'vue';
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

type Supplier = {
    id: number;
    name: string;
    contactName: string;
    email: string;
    phone: string;
    billingAddress: string;
    isActive: boolean;
};

const props = defineProps<{
    suppliers: Supplier[];
    canManage: boolean;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Suppliers',
                href: layoutProps.currentTeam
                    ? `/${layoutProps.currentTeam.slug}/suppliers`
                    : '/suppliers',
            },
        ],
    }),
});

const page = usePage();
const dialogOpen = ref(false);
const editingSupplier = ref<Supplier | null>(null);
const saving = ref(false);
const formError = ref('');
const form = ref({
    name: '',
    contact_name: '',
    email: '',
    phone: '',
    billing_address: '',
    is_active: true,
});

function currentTeamSlug(): string | null {
    const team = page.props.currentTeam as { slug: string } | null | undefined;

    return team?.slug ?? null;
}

function openCreate(): void {
    editingSupplier.value = null;
    form.value = {
        name: '',
        contact_name: '',
        email: '',
        phone: '',
        billing_address: '',
        is_active: true,
    };
    formError.value = '';
    dialogOpen.value = true;
}

function openEdit(supplier: Supplier): void {
    editingSupplier.value = supplier;
    form.value = {
        name: supplier.name,
        contact_name: supplier.contactName,
        email: supplier.email,
        phone: supplier.phone,
        billing_address: supplier.billingAddress,
        is_active: supplier.isActive,
    };
    formError.value = '';
    dialogOpen.value = true;
}

function saveSupplier(): void {
    const slug = currentTeamSlug();

    if (!slug || saving.value) {
        return;
    }

    saving.value = true;
    formError.value = '';
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            formError.value =
                Object.values(errors)[0] ?? 'Unable to save this supplier.';
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (editingSupplier.value) {
        router.patch(
            `/${slug}/suppliers/${editingSupplier.value.id}`,
            form.value,
            options,
        );
    } else {
        router.post(`/${slug}/suppliers`, form.value, options);
    }
}

function archiveSupplier(supplier: Supplier): void {
    const slug = currentTeamSlug();

    if (slug && window.confirm(`Archive ${supplier.name}?`)) {
        router.delete(`/${slug}/suppliers/${supplier.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head title="Suppliers" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Suppliers</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Keep supplier contacts and billing details together for your
                    team.
                </p>
                <p v-if="!canManage" class="mt-2 text-xs text-muted-foreground">
                    You have read-only access. Ask a team admin to make changes.
                </p>
            </div>
            <Button v-if="canManage" size="sm" @click="openCreate">
                <Plus class="size-4" /> Add supplier
            </Button>
        </header>

        <section
            v-if="props.suppliers.length"
            class="overflow-hidden rounded-lg border bg-card"
        >
            <ul class="divide-y">
                <li
                    v-for="supplier in props.suppliers"
                    :key="supplier.id"
                    class="flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-center"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted/30"
                        >
                            <Truck class="size-4 text-muted-foreground" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-medium">{{ supplier.name }}</h2>
                                <Badge
                                    :variant="
                                        supplier.isActive
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        supplier.isActive
                                            ? 'Active'
                                            : 'Inactive'
                                    }}
                                </Badge>
                            </div>
                            <p
                                v-if="supplier.contactName"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{ supplier.contactName }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                <a
                                    v-if="supplier.email"
                                    :href="`mailto:${supplier.email}`"
                                    class="hover:underline"
                                    >{{ supplier.email }}</a
                                >
                                <span v-if="supplier.email && supplier.phone">
                                    ·
                                </span>
                                <a
                                    v-if="supplier.phone"
                                    :href="`tel:${supplier.phone}`"
                                    class="hover:underline"
                                    >{{ supplier.phone }}</a
                                >
                            </p>
                            <p
                                v-if="supplier.billingAddress"
                                class="mt-1 text-xs whitespace-pre-line text-muted-foreground"
                            >
                                {{ supplier.billingAddress }}
                            </p>
                        </div>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="openEdit(supplier)"
                        >
                            <Pencil class="size-4" /> Edit
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="archiveSupplier(supplier)"
                        >
                            <Trash2 class="size-4" /> Archive
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
        <section
            v-else
            class="rounded-lg border border-dashed px-6 py-12 text-center"
        >
            <Truck class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 font-medium">No suppliers yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Add a supplier to keep purchasing contacts in one place.
            </p>
            <Button v-if="canManage" class="mt-4" @click="openCreate"
                ><Plus class="size-4" /> Add supplier</Button
            >
        </section>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-xl">
                <form class="grid gap-5" @submit.prevent="saveSupplier">
                    <DialogHeader class="text-left">
                        <DialogTitle>{{
                            editingSupplier ? 'Edit supplier' : 'Add supplier'
                        }}</DialogTitle>
                        <DialogDescription
                            >Supplier contact and billing details are only
                            visible to your team.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="supplier-name">Supplier name</Label>
                            <Input
                                id="supplier-name"
                                v-model="form.name"
                                required
                                maxlength="255"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="supplier-contact">Contact name</Label>
                            <Input
                                id="supplier-contact"
                                v-model="form.contact_name"
                                maxlength="255"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="supplier-email">Email</Label>
                            <Input
                                id="supplier-email"
                                v-model="form.email"
                                type="email"
                                maxlength="255"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="supplier-phone">Phone</Label>
                            <Input
                                id="supplier-phone"
                                v-model="form.phone"
                                type="tel"
                                maxlength="50"
                            />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="supplier-address"
                                >Billing address</Label
                            >
                            <textarea
                                id="supplier-address"
                                v-model="form.billing_address"
                                rows="3"
                                maxlength="2000"
                                class="rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                            />
                        </div>
                        <label
                            v-if="editingSupplier"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="size-4 rounded border-input"
                            />
                            Active supplier
                        </label>
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
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="saving">{{
                            saving ? 'Saving…' : 'Save supplier'
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
