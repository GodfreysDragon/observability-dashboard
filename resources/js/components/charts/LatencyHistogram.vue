<script setup>
import { computed, ref } from 'vue';
import { niceTicks, useWidth } from './scale';
import { ms, msTick, number, percent } from '../../lib/format';

/**
 * Latency distribution from the log-scale histogram buckets. Bars are equal width on a
 * log axis, so a long tail stays readable; p50/p95/p99 are marked on top.
 */
const props = defineProps({
    /** [{ from, to, count }] */
    buckets: { type: Array, required: true },
    percentiles: { type: Object, default: () => ({}) },
    height: { type: Number, default: 200 },
});

const { el, width } = useWidth();
const hover = ref(null);
const margin = { top: 22, right: 10, bottom: 26, left: 44 };

const plotW = computed(() => Math.max(10, width.value - margin.left - margin.right));
const plotH = computed(() => props.height - margin.top - margin.bottom);
const total = computed(() => props.buckets.reduce((sum, b) => sum + b.count, 0));
const ticks = computed(() => niceTicks(Math.max(0, ...props.buckets.map((b) => b.count))));
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1);
const slot = computed(() => plotW.value / Math.max(1, props.buckets.length));
const barW = computed(() => Math.max(2, slot.value - 2));

const y = (v) => margin.top + plotH.value - (v / yMax.value) * plotH.value;
const left = (i) => margin.left + slot.value * i + 1;

/** Position of a value on the log x-axis. */
function xFor(value) {
    const index = props.buckets.findIndex((b) => b.to === null || value <= b.to);
    if (index < 0) return margin.left + plotW.value;
    const b = props.buckets[index];
    const lower = Math.max(b.from, 0.001);
    const fraction = b.to === null ? 0.5 : Math.min(1, Math.max(0, Math.log(value / lower) / Math.log(b.to / lower)));
    return margin.left + slot.value * (index + fraction);
}

function barPath(i, count) {
    const top = y(count);
    const base = y(0);
    const x0 = left(i);
    const w = barW.value;
    const r = Math.min(3, w / 2, base - top);
    if (count <= 0 || base - top < 0.5) return '';
    return `M${x0},${base}V${top + r}Q${x0},${top} ${x0 + r},${top}H${x0 + w - r}Q${x0 + w},${top} ${x0 + w},${top + r}V${base}Z`;
}

const marks = computed(() =>
    ['p50', 'p95', 'p99']
        .filter((k) => props.percentiles[k] !== null && props.percentiles[k] !== undefined)
        .map((k) => ({ key: k, value: props.percentiles[k], x: xFor(props.percentiles[k]) })),
);

const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(props.buckets.length / Math.max(2, Math.floor(plotW.value / 56))));
    return props.buckets.map((b, i) => ({ i, label: msTick(b.from) })).filter((l) => l.i % every === 0);
});
</script>

<template>
    <div ref="el" class="relative" :style="{ height: `${height}px` }">
        <svg :width="width" :height="height" class="block" role="img" aria-label="Latency distribution">
            <line
                v-for="t in ticks"
                :key="t"
                :x1="margin.left"
                :x2="margin.left + plotW"
                :y1="y(t)"
                :y2="y(t)"
                :stroke="t === 0 ? 'var(--axis)' : 'var(--grid)'"
                shape-rendering="crispEdges"
            />
            <text v-for="t in ticks" :key="`l${t}`" :x="margin.left - 8" :y="y(t)" dy="0.32em" text-anchor="end" class="fill-muted text-[11px] tabular-nums">{{ number(t) }}</text>
            <text v-for="l in xLabels" :key="`x${l.i}`" :x="left(l.i)" :y="height - 6" class="fill-muted text-[11px]">{{ l.label }}</text>

            <g v-for="(b, i) in buckets" :key="i">
                <path :d="barPath(i, b.count)" fill="var(--series-1)" :opacity="hover === null || hover === i ? 1 : 0.55" />
                <rect :x="margin.left + slot * i" :y="margin.top" :width="slot" :height="plotH" fill="transparent" @pointerenter="hover = i" @pointerleave="hover = null" />
            </g>

            <g v-for="m in marks" :key="m.key">
                <line :x1="m.x" :x2="m.x" :y1="margin.top - 6" :y2="y(0)" stroke="var(--ink-2)" stroke-dasharray="3 3" />
                <text :x="m.x" :y="margin.top - 9" text-anchor="middle" class="fill-ink-2 text-[10px] font-medium">{{ m.key }}</text>
            </g>
        </svg>
        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-0 z-10 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg"
            :style="left(hover) > width - 180 ? { right: `${width - left(hover) + 8}px` } : { left: `${left(hover) + slot + 8}px` }"
            role="status"
        >
            <div class="font-semibold text-ink tabular-nums">{{ number(buckets[hover].count, { exact: true }) }} requests · {{ percent(buckets[hover].count / total) }}</div>
            <div class="text-muted">{{ ms(buckets[hover].from) }} – {{ buckets[hover].to === null ? '∞' : ms(buckets[hover].to) }}</div>
        </div>
    </div>
</template>
