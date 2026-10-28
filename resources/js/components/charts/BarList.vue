<script setup>
import { computed } from 'vue';

/**
 * Ranked horizontal bars with the label above and the value at the bar's tip.
 * One series = one colour unless a row brings its own (entity colour, e.g. a platform).
 */
const props = defineProps({
    /** [{ key, label, value, color?, note? }] */
    rows: { type: Array, required: true },
    format: { type: Function, default: (v) => v },
    color: { type: String, default: 'var(--series-1)' },
    max: { type: Number, default: null },
});

const scaleMax = computed(() => props.max ?? Math.max(1e-9, ...props.rows.map((r) => r.value)));
</script>

<template>
    <ul class="space-y-2.5">
        <li v-for="row in rows" :key="row.key ?? row.label">
            <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span class="truncate text-ink">
                    <slot name="label" :row="row">{{ row.label }}</slot>
                </span>
                <span class="shrink-0 text-xs text-muted tabular-nums">{{ row.note }}</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="h-2.5 flex-1">
                    <div
                        class="h-full rounded-r-[4px]"
                        :style="{ width: `${Math.max(0.5, (row.value / scaleMax) * 100)}%`, background: row.color ?? color }"
                    />
                </div>
                <span class="w-16 shrink-0 text-right text-xs font-medium text-ink-2 tabular-nums">{{ format(row.value) }}</span>
            </div>
        </li>
    </ul>
</template>
