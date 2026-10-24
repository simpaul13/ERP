<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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

type EditableWarehouse = {
    id: number;
    name: string;
    code: string;
    address: string;
    isDefault: boolean;
};

const props = defineProps<{
    open: boolean;
    warehouse?: EditableWarehouse | null;
    hasWarehouses?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [];
}>();

const page = usePage();
const form = ref({
    name: '',
    code: '',
    address: '',
    isDefault: false,
});
const saving = ref(false);
const error = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        error.value = '';

        form.value = props.warehouse
            ? {
                  name: props.warehouse.name,
                  code: props.warehouse.code,
                  address: props.warehouse.address,
                  isDefault: props.warehouse.isDefault,
              }
            : {
                  name: '',
                  code: '',
                  address: '',
                  isDefault: !props.hasWarehouses,
              };
    },
    { immediate: true },
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

function save(): void {
    const slug = currentTeamSlug();

    if (!slug || saving.value || !form.value.name.trim()) {
        return;
    }

    const url = props.warehouse
        ? `/${slug}/warehouses/${props.warehouse.id}`
        : `/${slug}/warehouses`;

    error.value = '';
    saving.value = true;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('update:open', false);
        },
        onError: (errors: Record<string, string>) => {
            error.value = firstError(errors);
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    const payload = {
        name: form.value.name.trim(),
        code: form.value.code.trim(),
        address: form.value.address.trim(),
        is_default: form.value.isDefault,
    };

    if (props.warehouse) {
        router.patch(url, payload, options);
    } else {
        router.post(url, payload, options);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        warehouse ? 'Edit warehouse' : 'Add warehouse'
                    }}</DialogTitle>
                    <DialogDescription>
                        A warehouse is a physical place that holds stock, such
                        as a store room or distribution centre.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="warehouse-name">Name</Label>
                        <Input
                            id="warehouse-name"
                            v-model="form.name"
                            required
                            placeholder="e.g. North warehouse"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="warehouse-code">Code</Label>
                        <Input
                            id="warehouse-code"
                            v-model="form.code"
                            placeholder="Auto-generated if left blank"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="warehouse-address">Address</Label>
                        <Input
                            id="warehouse-address"
                            v-model="form.address"
                            placeholder="Optional"
                        />
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm"
                        for="warehouse-default"
                    >
                        <input
                            id="warehouse-default"
                            v-model="form.isDefault"
                            type="checkbox"
                            class="size-4 rounded border"
                        />
                        Use as the team's default warehouse
                    </label>
                </div>
                <p v-if="error" class="text-xs text-destructive" role="alert">
                    {{ error }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="saving">
                        {{
                            saving
                                ? 'Saving…'
                                : warehouse
                                  ? 'Save changes'
                                  : 'Add warehouse'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
