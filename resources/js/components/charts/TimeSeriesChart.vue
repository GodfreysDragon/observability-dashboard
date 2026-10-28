<script setup>
import { computed, ref } from 'vue';
import { niceTicks, tickIndices, useWidth } from './scale';
import { timeTick, timeTitle } from '../../lib/format';

const props = defineProps({
    /** [{ key, label, color, points: [{ t, value }] }] - every series shares the same timestamps; null = no data */
    series: { type: Array, required: true },
    step: { type: Number, default: 60 },
    format: { type: Function, default: (v) => v },
    tickFormat: { type: Function, default: null },
    height: { type: Number, default: 220 },
    /** Columns instead of lines (single-series counts). */
    bars: { type: Boolean, default: false },
    area: { type: Boolean, default: false },
    /** Deploy markers: [{ t, label }] */
    markers: { type: Array, default: () => [] },
    /** A horizontal reference line, e.g. an SLO threshold: { value, label } */
    threshold: { type: Object, default: null },
    yMaxValue: { type: Number, default: null },
    ariaLabel: { type: String, default: 'Time series chart' },
});

const { el, width } = useWidth();
const hover = ref(null);

const showEndLabels = computed(() => !props.bars && props.series.length >= 2 && props.series.length <= 4);
const margin = computed(() => ({ top: 16, right: showEndLabels.value ? 40 : 14, bottom: 26, left: 52 }));
const plotW = computed(() => Math.max(10, width.value - margin.value.left - margin.value.right));
const plotH = computed(() => props.height - margin.value.top - margin.value.bottom);

const times = computed(() => props.series[0]?.points.map((p) => p.t) ?? []);
const max = computed(() => Math.max(0, props.threshold?.value ?? 0, ...props.series.flatMap((s) => s.points.map((p) => p.value ?? 0))));
const ticks = computed(() => niceTicks(props.yMaxValue ?? max.value));
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1);
const slot = computed(() => plotW.value / Math.max(1, times.value.length));

const x = (i) => (props.bars ? margin.value.left + slot.value * (i + 0.5) : margin.value.left + (times.value.length <= 1 ? plotW.value / 2 : (i / (times.value.length - 1)) * plotW.value));
const y = (v) => margin.value.top + plotH.value - (Math.min(v, yMax.value) / yMax.value) * plotH.value;

/** Lines break across buckets with no data rather than diving to zero. */
const paths = computed(() =>
    props.series.map((s) => {
        let line = '';
        let fill = '';
        let run = [];
        const flush = () => {
            if (!run.length) return;
            const segment = run.map(([i, v], j) => `${j ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('');
            line += segment;
            fill += `${segment}L${x(run[run.length - 1][0]).toFixed(1)},${y(0).toFixed(1)}L${x(run[0][0]).toFixed(1)},${y(0).toFixed(1)}Z`;
            run = [];
        };
        s.points.forEach((p, i) => (p.value === null || p.value === undefined ? flush() : run.push([i, p.value])));
        flush();
        return { ...s, line, fill };
    }),
);

const barW = computed(() => Math.max(1.5, Math.min(18, slot.value - 2)));

function columnPath(i, value) {
    const w = barW.value;
    const x0 = x(i) - w / 2;
    const top = y(value);
    const base = y(0);
    const r = Math.min(3, w / 2, base - top);
    if (!(value > 0) || base - top < 0.5) return '';
    return `M${x0},${base}V${top + r}Q${x0},${top} ${x0 + r},${top}H${x0 + w - r}Q${x0 + w},${top} ${x0 + w},${top + r}V${base}Z`;
}

const xTicks = computed(() => tickIndices(times.value.length, Math.max(2, Math.floor(plotW.value / 96))));

const markerPositions = computed(() => {
    if (!times.value.length) return [];
    const first = times.value[0];
    const last = times.value[times.value.length - 1] + props.step;
    return props.markers
        .filter((m) => m.t >= first && m.t < last)
        .map((m) => {
            const fraction = (m.t - first) / props.step;
            const px = props.bars ? margin.value.left + slot.value * fraction : margin.value.left + (fraction / Math.max(1, times.value.length - 1)) * plotW.value;
            return { ...m, x: Math.min(margin.value.left + plotW.value, px) };
        });
});

const endLabels = computed(() => {
    if (!showEndLabels.value || !times.value.length) return [];
    const labels = props.series
        .map((s) => {
            const lastIndex = s.points.findLastIndex((p) => p.value !== null && p.value !== undefined);
            return lastIndex < 0 ? null : { key: s.key, label: s.short ?? s.label, y: y(s.points[lastIndex].value) };
        })
        .filter(Boolean)
        .sort((a, b) => a.y - b.y);
    return labels.some((l, i) => i > 0 && l.y - labels[i - 1].y < 13) ? [] : labels;
});

function onMove(event) {
    if (!times.value.length) return;
    const rect = el.value.getBoundingClientRect();
    const px = event.clientX - rect.left - margin.value.left;
    const index = props.bars ? Math.floor(px / slot.value) : Math.round((px / Math.max(1, plotW.value)) * (times.value.length - 1));
    hover.value = Math.min(times.value.length - 1, Math.max(0, index));
}

function onKey(event) {
    const last = times.value.length - 1;
    if (event.key === 'ArrowRight') hover.value = Math.min(last, (hover.value ?? -1) + 1);
    else if (event.key === 'ArrowLeft') hover.value = Math.max(0, (hover.value ?? last + 1) - 1);
    else if (event.key === 'Home') hover.value = 0;
    else if (event.key === 'End') hover.value = last;
    else return;
    event.preventDefault();
}

const tooltip = computed(() => {
    if (hover.value === null || !times.value.length) return null;
    const i = hover.value;
    const t = times.value[i];
    const deploys = props.markers.filter((m) => m.t >= t && m.t < t + props.step);
    return {
        title: timeTitle(t),
        left: x(i),
        flip: x(i) > width.value - 210,
        deploys,
        rows: props.series.map((s) => ({ key: s.key, label: s.label, color: s.color, value: s.points[i]?.value === null || s.points[i]?.value === undefined ? 'no data' : props.format(s.points[i].value) })),
    };
});

const tick = (v) => (props.tickFormat ?? props.format)(v);
</script>

<template>
    <div>
        <ul v-if="series.length >= 2 || markers.length || threshold" class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
            <template v-if="series.length >= 2">
                <li v-for="s in series" :key="s.key" class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-3.5 rounded-full" :style="{ background: s.color }" aria-hidden="true" />
                    {{ s.label }}
                </li>
            </template>
            <li v-if="markers.length" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-3 border-l border-dashed border-ink-2" aria-hidden="true" />
                Deploy
            </li>
            <li v-if="threshold" class="inline-flex items-center gap-1.5">
                <span class="inline-block w-3.5 border-t border-dashed border-status-critical" aria-hidden="true" />
                {{ threshold.label }}
            </li>
        </ul>
        <div ref="el" class="relative" :style="{ height: `${height}px` }">
            <svg
                :width="width"
                :height="height"
                class="block touch-pan-y outline-none"
                role="img"
                :aria-label="ariaLabel"
                tabindex="0"
                @pointermove="onMove"
                @pointerdown="onMove"
                @pointerleave="hover = null"
                @keydown="onKey"
                @blur="hover = null"
            >
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
                <text v-for="t in ticks" :key="`l${t}`" :x="margin.left - 8" :y="y(t)" dy="0.32em" text-anchor="end" class="fill-muted text-[11px] tabular-nums">{{ tick(t) }}</text>
                <text
                    v-for="i in xTicks"
                    :key="`x${i}`"
                    :x="x(i)"
                    :y="height - 6"
                    :text-anchor="i === 0 ? 'start' : i === times.length - 1 ? 'end' : 'middle'"
                    class="fill-muted text-[11px]"
                >
                    {{ timeTick(times[i], step) }}
                </text>

                <g v-for="m in markerPositions" :key="`m${m.t}`">
                    <line :x1="m.x" :x2="m.x" :y1="margin.top - 4" :y2="y(0)" stroke="var(--ink-2)" stroke-dasharray="3 3" stroke-width="1" />
                    <text :x="m.x + 4" :y="margin.top - 5" class="fill-ink-2 text-[10px] font-medium">{{ m.label }}</text>
                </g>

                <line
                    v-if="threshold"
                    :x1="margin.left"
                    :x2="margin.left + plotW"
                    :y1="y(threshold.value)"
                    :y2="y(threshold.value)"
                    stroke="var(--status-critical)"
                    stroke-dasharray="4 3"
                    stroke-width="1"
                />

                <template v-if="bars">
                    <path
                        v-for="(p, i) in series[0]?.points ?? []"
                        :key="p.t"
                        :d="columnPath(i, p.value)"
                        :fill="series[0].color"
                        :opacity="hover === null || hover === i ? 1 : 0.55"
                    />
                </template>
                <g v-for="s in paths" v-else :key="s.key">
                    <path v-if="area" :d="s.fill" :fill="s.color" fill-opacity="0.1" />
                    <path :d="s.line" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                </g>

                <text v-for="l in endLabels" :key="`e${l.key}`" :x="margin.left + plotW + 6" :y="l.y" dy="0.32em" class="fill-ink-2 text-[11px]">{{ l.label }}</text>

                <g v-if="tooltip && !bars">
                    <line :x1="tooltip.left" :x2="tooltip.left" :y1="margin.top" :y2="y(0)" stroke="var(--ink-2)" stroke-width="1" shape-rendering="crispEdges" />
                    <template v-for="s in series" :key="`d${s.key}`">
                        <circle
                            v-if="s.points[hover]?.value !== null && s.points[hover]?.value !== undefined"
                            :cx="tooltip.left"
                            :cy="y(s.points[hover].value)"
                            r="4"
                            :fill="s.color"
                            stroke="var(--surface-1)"
                            stroke-width="2"
                        />
                    </template>
                </g>
            </svg>

            <div
                v-if="tooltip"
                class="pointer-events-none absolute top-1 z-10 min-w-40 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg"
                :style="tooltip.flip ? { right: `${width - tooltip.left + 12}px` } : { left: `${tooltip.left + 12}px` }"
                role="status"
            >
                <div class="mb-1 text-muted">{{ tooltip.title }}</div>
                <div v-for="row in tooltip.rows" :key="row.key" class="flex items-center gap-2">
                    <span class="inline-block h-0.5 w-3 rounded-full" :style="{ background: row.color }" aria-hidden="true" />
                    <span class="font-semibold text-ink tabular-nums">{{ row.value }}</span>
                    <span v-if="series.length > 1" class="text-ink-2">{{ row.label }}</span>
                </div>
                <div v-for="d in tooltip.deploys" :key="d.t" class="mt-1 border-t border-line pt-1 text-ink-2">Deploy {{ d.label }}</div>
            </div>
        </div>
    </div>
</template>
