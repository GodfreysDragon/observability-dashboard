<script setup>
import { computed } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import BarList from '../components/charts/BarList.vue';
import Sparkline from '../components/charts/Sparkline.vue';
import StatTile from '../components/charts/StatTile.vue';
import TimeSeriesChart from '../components/charts/TimeSeriesChart.vue';
import RouteName from '../components/RouteName.vue';
import StatusDot from '../components/StatusDot.vue';
import { api } from '../lib/api';
import { ms, msTick, number, percent, rpm, seconds, shortClass, timeAgo } from '../lib/format';
import { deployMarkers, LATENCY_SERIES, previousLabel, STEPS, toSeries } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/overview', params, { signal }),
);

const step = computed(() => STEPS[state.range]);
const markers = computed(() => deployMarkers(data.value?.deploys));
const series = computed(() => data.value?.series ?? []);
const change = (key) => {
    const current = data.value?.current?.[key];
    const previous = data.value?.previous?.[key];
    return current === null || current === undefined || !previous ? null : (current - previous) / previous;
};

const tiles = computed(() => {
    const c = data.value?.current ?? {};
    return [
        { label: 'Throughput', value: `${rpm(c.rpm)} rpm`, change: change('rpm'), upIsGood: true, trend: series.value.map((p) => p.rpm ?? 0) },
        { label: 'Server error rate', value: percent(c.error_rate, 2), change: change('error_rate'), upIsGood: false, trend: series.value.map((p) => p.error_rate ?? 0) },
        { label: 'p95 latency', value: ms(c.p95), change: change('p95'), upIsGood: false, trend: series.value.map((p) => p.p95 ?? 0) },
        { label: `Apdex (T = 300 ms)`, value: c.apdex === null || c.apdex === undefined ? '–' : c.apdex.toFixed(2), change: change('apdex'), upIsGood: true, trend: series.value.map((p) => p.apdex ?? 0) },
    ];
});

const sloStatus = { ok: ['ok', 'Within budget'], warning: ['warning', 'Slow burn'], critical: ['critical', 'Fast burn'], exhausted: ['critical', 'Budget exhausted'] };
const latencyThreshold = computed(() => (data.value?.slo?.latency_ms ? { value: data.value.slo.latency_ms, label: `Latency SLO (${ms(data.value.slo.latency_ms)})` } : null));

const timeRows = computed(() =>
    (data.value?.routes ?? []).map((r) => ({ key: r.route, label: r.route, value: r.time_share, note: `${number(r.count)} req · p95 ${ms(r.p95)}` })),
);
</script>

<template>
    <div v-if="error" class="card p-6 text-sm text-bad" role="alert">Could not load the overview: {{ error.message }}</div>
    <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
    <div v-else class="space-y-6 transition-opacity" :class="loading ? 'opacity-60' : ''">
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="Service level objectives">
            <div v-for="o in data.slo.objectives" :key="o.key" class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="text-sm text-ink-2">{{ o.label }} · {{ data.slo.window_days }}-day SLO</div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="text-2xl font-semibold text-ink tabular-nums">{{ percent(o.attained, 2) }}</span>
                            <span class="text-sm text-muted">target {{ percent(o.target, 1) }}</span>
                        </div>
                    </div>
                    <StatusDot :status="sloStatus[o.status][0]" :label="sloStatus[o.status][1]" />
                </div>
                <div class="mt-3">
                    <div class="mb-1 flex justify-between text-xs text-ink-2">
                        <span>Error budget remaining</span>
                        <span class="font-medium text-ink tabular-nums">{{ o.budget_remaining === null ? '–' : percent(Math.max(0, o.budget_remaining), 0) }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-surface-2" role="meter" :aria-valuenow="Math.max(0, o.budget_remaining ?? 0)" aria-valuemin="0" aria-valuemax="1" :aria-label="`${o.label} error budget remaining`">
                        <div
                            class="h-full rounded-full"
                            :style="{ width: `${Math.max(0, Math.min(1, o.budget_remaining ?? 0)) * 100}%`, background: o.budget_remaining > 0.25 ? 'var(--series-1)' : 'var(--status-critical)' }"
                        />
                    </div>
                    <div class="mt-2 flex gap-4 text-xs text-muted">
                        <span>Burn rate 1h <strong class="font-medium text-ink-2 tabular-nums">{{ o.burn_rate_1h ?? '–' }}×</strong></span>
                        <span>6h <strong class="font-medium text-ink-2 tabular-nums">{{ o.burn_rate_6h ?? '–' }}×</strong></span>
                        <span class="hidden sm:inline">pages at 14.4×, tickets at 6×</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key metrics">
            <StatTile v-for="t in tiles" :key="t.label" v-bind="t" :compared-to="previousLabel(state.range)" />
        </section>

        <ChartCard title="Latency percentiles" :subtitle="`All routes · estimated from log-bucket histograms · dashed lines are deploys`" :loading="loading">
            <TimeSeriesChart
                :series="toSeries(series, LATENCY_SERIES)"
                :step="step"
                :markers="markers"
                :threshold="latencyThreshold"
                :format="ms"
                :tick-format="msTick"
                :height="240"
                aria-label="Latency percentiles over time"
            />
            <template #table>
                <table class="data-table">
                    <thead><tr><th>Time</th><th class="text-right">Requests</th><th class="text-right">p50</th><th class="text-right">p95</th><th class="text-right">p99</th></tr></thead>
                    <tbody>
                        <tr v-for="p in series" :key="p.t"><td>{{ new Date(p.t * 1000).toLocaleString() }}</td><td class="text-right">{{ number(p.count) }}</td><td class="text-right">{{ ms(p.p50) }}</td><td class="text-right">{{ ms(p.p95) }}</td><td class="text-right">{{ ms(p.p99) }}</td></tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <ChartCard title="Throughput" subtitle="Requests per minute" :loading="loading" :table-toggle="false">
                <TimeSeriesChart :series="toSeries(series, [{ key: 'rpm', label: 'Requests / min', color: 'var(--series-1)' }])" :step="step" :markers="markers" :format="rpm" area :height="180" aria-label="Throughput over time" />
            </ChartCard>
            <ChartCard title="Server error rate" subtitle="Share of requests answered with a 5xx" :loading="loading" :table-toggle="false">
                <TimeSeriesChart
                    :series="toSeries(series, [{ key: 'error_rate', label: '5xx rate', color: 'var(--series-2)' }])"
                    :step="step"
                    :markers="markers"
                    :format="(v) => percent(v, 2)"
                    :height="180"
                    aria-label="Error rate over time"
                />
            </ChartCard>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <ChartCard class="lg:col-span-3" title="Where the time goes" subtitle="Share of total server time (requests × mean duration) by route" :loading="loading">
                <BarList :rows="timeRows" :format="(v) => percent(v, 0)">
                    <template #label="{ row }"><RouteName :route="row.label" /></template>
                </BarList>
                <template #table>
                    <table class="data-table">
                        <thead><tr><th>Route</th><th class="text-right">Requests</th><th class="text-right">Time share</th><th class="text-right">p95</th><th class="text-right">5xx</th></tr></thead>
                        <tbody>
                            <tr v-for="r in data.routes" :key="r.route"><td><RouteName :route="r.route" /></td><td class="text-right">{{ number(r.count) }}</td><td class="text-right">{{ percent(r.time_share) }}</td><td class="text-right">{{ ms(r.p95) }}</td><td class="text-right">{{ percent(r.error_rate, 2) }}</td></tr>
                        </tbody>
                    </table>
                </template>
                <router-link :to="{ name: 'routes' }" class="link mt-4 inline-block text-sm">All routes →</router-link>
            </ChartCard>

            <div class="space-y-6 lg:col-span-2">
                <section class="card p-4 sm:p-5">
                    <header class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-sm font-semibold text-ink">Queues</h2>
                        <router-link :to="{ name: 'queues' }" class="link text-xs">Details →</router-link>
                    </header>
                    <table class="data-table">
                        <thead><tr><th>Queue</th><th class="text-right">Jobs/min</th><th class="text-right">Failed</th><th class="text-right">Backlog</th></tr></thead>
                        <tbody>
                            <tr v-for="q in data.queues" :key="q.queue">
                                <td class="font-medium text-ink">{{ q.queue }}</td>
                                <td class="text-right">{{ rpm(q.jobs_per_minute) }}</td>
                                <td class="text-right">{{ number(q.failed) }}</td>
                                <td class="text-right">
                                    {{ number(q.pending) }}
                                    <span v-if="q.oldest_pending_s" class="block text-xs text-muted">oldest {{ seconds(q.oldest_pending_s) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="card p-4 sm:p-5">
                    <header class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-sm font-semibold text-ink">Open exceptions</h2>
                        <router-link :to="{ name: 'exceptions' }" class="link text-xs">All →</router-link>
                    </header>
                    <p v-if="!data.exceptions.length" class="text-sm text-muted">No unresolved exceptions in this range.</p>
                    <ul class="space-y-3">
                        <li v-for="e in data.exceptions" :key="e.id">
                            <router-link :to="{ name: 'exception', params: { id: e.id } }" class="group flex items-center justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate text-sm font-medium text-ink group-hover:underline">{{ shortClass(e.class) }}</span>
                                        <span v-if="e.is_new" class="badge !py-0">new in {{ e.first_release }}</span>
                                    </span>
                                    <span class="block truncate text-xs text-muted">{{ e.message }}</span>
                                    <span class="block text-xs text-muted">{{ number(e.count) }} events · last {{ timeAgo(e.last_seen_at) }}</span>
                                </span>
                                <Sparkline :values="e.trend" :width="72" :height="22" color="var(--series-2)" :label="`${shortClass(e.class)} trend`" />
                            </router-link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
