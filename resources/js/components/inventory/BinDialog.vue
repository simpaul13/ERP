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

type EditableBin = {
    id: number;
    name: string;
    code: string;
};

const props = defineProps<{
    open: boolean;
    warehouseId: number | null;
    warehouseName?: string;
    bin?: EditableBin | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [];
}>();

const page = usePage();
const form = ref({ name: '', code: '' });
const saving = ref(false);
const error = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        error.value = '';
        form.value = props.bin
            ? { name: props.bin.name, code: props.bin.code }
            : { name: '', code: '' };
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
    const warehouseId = props.warehouseId;

    if (!slug || !warehouseId || saving.value || !form.value.name.trim()) {
        return;
    }

    const url = props.bin
        ? `/${slug}/warehouses/${warehouseId}/locations/${props.bin.id}`
        : `/${slug}/warehouses/${warehouseId}/locations`;

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
    };

    if (props.bin) {
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
                        bin ? 'Edit bin' : 'Add bin'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ warehouseName }} · Bins let you pinpoint where an
                        item is stored inside the warehouse.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="bin-name">Name</Label>
                        <Input
                            id="bin-name"
                            v-model="form.name"
                            required
                            placeholder="e.g. Aisle A · Shelf 2"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bin-code">Code</Label>
                        <Input
                            id="bin-code"
                            v-model="form.code"
                            placeholder="Auto-generated if left blank"
                        />
                    </div>
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
                                : bin
                                  ? 'Save changes'
                                  : 'Add bin'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
