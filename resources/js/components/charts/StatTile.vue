<script setup>
import { computed } from 'vue';
import { signedPercent } from '../../lib/format';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    /** Fractional change vs the previous period, e.g. 0.12 = +12% */
    change: { type: Number, default: null },
    upIsGood: { type: Boolean, default: true },
    comparedTo: { type: String, default: 'previous period' },
    /** Optional daily values for a sparkline */
    trend: { type: Array, default: null },
    hero: { type: Boolean, default: false },
});

const tone = computed(() => {
    if (props.change === null || Math.abs(props.change) < 0.005) return 'text-muted';
    return props.change > 0 === props.upIsGood ? 'text-good' : 'text-bad';
});

const arrow = computed(() => (props.change === null || Math.abs(props.change) < 0.005 ? '' : props.change > 0 ? '▲' : '▼'));

const spark = computed(() => {
    if (!props.trend?.length) return null;
    const w = 96;
    const h = 28;
    const max = Math.max(1e-9, ...props.trend);
    const step = props.trend.length > 1 ? w / (props.trend.length - 1) : 0;
    const pts = props.trend.map((v, i) => [i * step, h - 2 - (v / max) * (h - 4)]);
    return { w, h, d: pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(''), last: pts[pts.length - 1] };
});
</script>

<template>
    <div class="card flex flex-col justify-between gap-2 p-4">
        <div class="text-sm text-ink-2">{{ label }}</div>
        <div class="flex items-end justify-between gap-3">
            <div :class="hero ? 'text-5xl' : 'text-2xl'" class="font-semibold tracking-tight whitespace-nowrap text-ink">{{ value }}</div>
            <svg v-if="spark" :width="spark.w" :height="spark.h" class="mb-1 hidden shrink-0 overflow-visible sm:block" aria-hidden="true">
                <path :d="spark.d" fill="none" stroke="var(--series-other)" stroke-width="1.5" stroke-linejoin="round" />
                <circle :cx="spark.last[0]" :cy="spark.last[1]" r="3" fill="var(--accent)" stroke="var(--surface-1)" stroke-width="1.5" />
            </svg>
        </div>
        <div class="text-xs" :class="tone">
            <template v-if="change !== null">
                <span aria-hidden="true">{{ arrow }}</span> {{ signedPercent(change) }}
                <span class="text-muted">vs {{ comparedTo }}</span>
            </template>
            <span v-else class="text-muted">No comparison for {{ comparedTo }}</span>
        </div>
    </div>
</template>
