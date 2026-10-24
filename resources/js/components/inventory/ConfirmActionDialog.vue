<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        confirmLabel: string;
        processingLabel?: string;
        processing?: boolean;
        error?: string;
        destructive?: boolean;
    }>(),
    {
        processingLabel: 'Working…',
        processing: false,
        error: '',
        destructive: true,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    <slot />
                </DialogDescription>
            </DialogHeader>
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
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ processing ? processingLabel : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
