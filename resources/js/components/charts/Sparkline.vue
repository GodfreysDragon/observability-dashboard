<script setup>
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, required: true },
    width: { type: Number, default: 96 },
    height: { type: Number, default: 24 },
    color: { type: String, default: 'var(--series-1)' },
    label: { type: String, default: '' },
});

/** Columns for counts: zero buckets stay visibly empty, which matters for "when did it start". */
const bars = computed(() => {
    const max = Math.max(1e-9, ...props.values);
    const w = props.width / Math.max(1, props.values.length);
    return props.values.map((v, i) => {
        const h = v > 0 ? Math.max(1.5, (v / max) * props.height) : 0;
        return { x: i * w, w: Math.max(0.8, w - (w > 3 ? 1 : 0.2)), y: props.height - h, h };
    });
});
</script>

<template>
    <svg :width="width" :height="height" class="block shrink-0" role="img" :aria-label="label || 'Trend'">
        <line :x1="0" :x2="width" :y1="height - 0.5" :y2="height - 0.5" stroke="var(--axis)" />
        <rect v-for="(b, i) in bars" :key="i" :x="b.x" :y="b.y" :width="b.w" :height="b.h" :fill="color" />
    </svg>
</template>
