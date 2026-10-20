<script setup lang="ts" generic="T extends object">
type DataTableColumn = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    class?: string;
};

withDefaults(
    defineProps<{
        columns: DataTableColumn[];
        items: T[];
        rowKey: (item: T) => string | number;
        accessibleLabel: string;
        emptyTitle?: string;
        emptyDescription?: string;
        minWidth?: string;
    }>(),
    {
        emptyTitle: 'No items found',
        emptyDescription: 'Try changing your search or filters.',
        minWidth: '640px',
    },
);

defineSlots<{
    [name: `cell-${string}`]: (props: { item: T; value: unknown }) => unknown;
    empty?: () => unknown;
}>();

function cellValue(item: T, key: string): unknown {
    return (item as Record<string, unknown>)[key];
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" :style="{ minWidth }" :aria-label="accessibleLabel">
            <thead class="bg-muted/40 text-xs text-muted-foreground">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="px-4 py-3 font-medium"
                        :class="[
                            column.align === 'right' ? 'text-right' : 'text-left',
                            column.class,
                        ]"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="item in items" :key="rowKey(item)" class="hover:bg-muted/20">
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="px-4 py-3"
                        :class="[
                            column.align === 'right' ? 'text-right' : 'text-left',
                            column.class,
                        ]"
                    >
                        <slot
                            :name="`cell-${column.key}`"
                            :item="item"
                            :value="cellValue(item, column.key)"
                        >
                            {{ cellValue(item, column.key) ?? '—' }}
                        </slot>
                    </td>
                </tr>
                <tr v-if="items.length === 0">
                    <td :colspan="columns.length" class="px-4 py-14 text-center">
                        <slot name="empty">
                            <p class="font-medium">{{ emptyTitle }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ emptyDescription }}</p>
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
