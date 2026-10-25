<script setup lang="ts">
import { Label } from '@/components/ui/label';

defineProps<{ id: string; label: string; error?: string; hint?: string }>();
</script>

<template>
    <div
        class="flex min-w-0 flex-col gap-2"
        :data-invalid="!!error || undefined"
    >
        <Label :for="id">{{ label }}</Label>
        <slot
            :invalid="!!error"
            :describedby="error || hint ? `${id}-feedback` : undefined"
        />
        <p
            v-if="error"
            :id="`${id}-feedback`"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            :id="`${id}-feedback`"
            class="text-xs text-muted-foreground"
        >
            {{ hint }}
        </p>
    </div>
</template>
