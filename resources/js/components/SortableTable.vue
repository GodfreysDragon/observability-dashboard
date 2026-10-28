<script setup>
import { computed, ref } from 'vue';

/**
 * Data table with client-side sorting. Columns: [{ key, label, align?, sort?: (row) => value, class? }].
 * Cells render through the `cell-<key>` slot, falling back to the raw value.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    rowKey: { type: [String, Function], required: true },
    initialSort: { type: String, default: null },
    initialDesc: { type: Boolean, default: true },
    caption: { type: String, default: '' },
});

const sortKey = ref(props.initialSort);
const desc = ref(props.initialDesc);

const sorted = computed(() => {
    const column = props.columns.find((c) => c.key === sortKey.value);
    if (!column) return props.rows;
    const value = column.sort ?? ((row) => row[column.key]);
    return [...props.rows].sort((a, b) => {
        const x = value(a);
        const y = value(b);
        if (x === y) return 0;
        if (x === null || x === undefined) return 1;
        if (y === null || y === undefined) return -1;
        return (x < y ? -1 : 1) * (desc.value ? -1 : 1);
    });
});

function sortBy(column) {
    if (column.sortable === false) return;
    if (sortKey.value === column.key) desc.value = !desc.value;
    else {
        sortKey.value = column.key;
        desc.value = column.align === 'right';
    }
}

const keyOf = (row) => (typeof props.rowKey === 'function' ? props.rowKey(row) : row[props.rowKey]);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="data-table">
            <caption v-if="caption" class="sr-only">{{ caption }}</caption>
            <thead>
                <tr>
                    <th
                        v-for="c in columns"
                        :key="c.key"
                        :class="[c.align === 'right' ? 'text-right' : '', c.class]"
                        :aria-sort="sortKey === c.key ? (desc ? 'descending' : 'ascending') : undefined"
                        scope="col"
                    >
                        <button v-if="c.sortable !== false" type="button" class="inline-flex items-center gap-1 whitespace-nowrap hover:text-ink" @click="sortBy(c)">
                            {{ c.label }}
                            <span class="w-2 text-[9px]" aria-hidden="true">{{ sortKey === c.key ? (desc ? '▼' : '▲') : '' }}</span>
                        </button>
                        <span v-else>{{ c.label }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in sorted" :key="keyOf(row)" class="hover:bg-surface-2">
                    <td v-for="c in columns" :key="c.key" :class="[c.align === 'right' ? 'text-right whitespace-nowrap' : '', c.class]">
                        <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] }}</slot>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="columns.length" class="py-6 text-center text-muted"><slot name="empty">No data in this range.</slot></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
