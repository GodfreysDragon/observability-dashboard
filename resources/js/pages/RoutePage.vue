<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ChartCard from '../components/ChartCard.vue';
import LatencyHistogram from '../components/charts/LatencyHistogram.vue';
import StatTile from '../components/charts/StatTile.vue';
import TimeSeriesChart from '../components/charts/TimeSeriesChart.vue';
import RouteName from '../components/RouteName.vue';
import { api } from '../lib/api';
import { dateTime, ms, msTick, number, percent, rpm } from '../lib/format';
import { deployMarkers, LATENCY_SERIES, previousLabel, STEPS, toSeries } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const route = useRoute();
const name = computed(() => String(route.query.route ?? ''));

const { data, loading, error } = useReport(
    () => (name.value ? { range: state.range, route: name.value } : null),
    (params, signal) => api.get('/routes/detail', params, { signal }),
);

const step = computed(() => STEPS[state.range]);
const markers = computed(() => deployMarkers(data.value?.deploys));
const change = (key) => {
    const c = data.value?.current?.[key];
    const p = data.value?.previous?.[key];
    return c === null || c === undefined || !p ? null : (c - p) / p;
};

const tiles = computed(() => {
    const c = data.value?.current ?? {};
    return [
        { label: 'Requests', value: number(c.count), change: change('count'), upIsGood: true },
        { label: 'p95 latency', value: ms(c.p95), change: change('p95'), upIsGood: false },
        { label: 'Server error rate', value: percent(c.error_rate, 2), change: change('error_rate'), upIsGood: false },
        { label: 'Queries per request', value: c.queries_per_request ?? '–', change: change('queries_per_request'), upIsGood: false },
    ];
});

</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <router-link :to="{ name: 'routes' }" class="link text-sm">← Routes</router-link>
            <router-link :to="{ name: 'traces', query: { route: name } }" class="link text-sm">Kept traces for this route →</router-link>
        </div>
        <div class="card px-4 py-3 text-base"><RouteName :route="name" :link="false" /></div>

        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
        <template v-else>
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile v-for="t in tiles" :key="t.label" v-bind="t" :compared-to="previousLabel(state.range)" />
            </section>

            <ChartCard title="Latency percentiles" subtitle="Dashed lines are deploys" :loading="loading" :table-toggle="false">
                <TimeSeriesChart :series="toSeries(data.series, LATENCY_SERIES)" :step="step" :markers="markers" :format="ms" :tick-format="msTick" :height="230" aria-label="Route latency percentiles" />
            </ChartCard>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <ChartCard title="Latency distribution" subtitle="Requests per histogram bucket (log scale, each bucket +25%)" :loading="loading" :table-toggle="false">
                    <LatencyHistogram :buckets="data.distribution" :percentiles="data.current" />
                </ChartCard>
                <ChartCard title="Queries per request" subtitle="Mean database queries per request: an N+1 shows up as a step" :loading="loading" :table-toggle="false">
                    <TimeSeriesChart
                        :series="toSeries(data.series, [{ key: 'queries_per_request', label: 'Queries / request', color: 'var(--series-1)' }])"
                        :step="step"
                        :markers="markers"
                        :format="(v) => number(v)"
                        :height="200"
                        aria-label="Queries per request"
                    />
                </ChartCard>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="card min-w-0 p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-ink">Slowest kept traces</h2>
                    <p class="mt-0.5 mb-3 text-xs text-muted">Slow and failed requests are always kept, so this tail is complete.</p>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>When</th><th>URL</th><th class="text-right">Status</th><th class="text-right">Duration</th><th class="text-right">Queries</th></tr></thead>
                            <tbody>
                                <tr v-for="t in data.slowest" :key="t.id" class="hover:bg-surface-2">
                                    <td class="whitespace-nowrap"><router-link :to="{ name: 'trace', params: { kind: 'requests', id: t.id } }" class="link">{{ dateTime(t.occurred_at) }}</router-link></td>
                                    <td class="mono max-w-48 truncate text-ink-2">{{ t.uri }}</td>
                                    <td class="text-right">{{ t.status }}</td>
                                    <td class="text-right font-medium text-ink">{{ ms(t.duration_ms) }}</td>
                                    <td class="text-right">{{ t.query_count }} <span v-if="t.n_plus_one" class="badge ml-1 !py-0 text-[10px]">N+1</span></td>
                                </tr>
                                <tr v-if="!data.slowest.length"><td colspan="5" class="text-center text-muted">No kept traces in this range.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="card min-w-0 p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-ink">Database time by query shape</h2>
                    <p class="mt-0.5 mb-3 text-xs text-muted">From kept traces of this route, which over-represent slow requests.</p>
                    <ul class="space-y-3">
                        <li v-for="q in data.queries" :key="q.fingerprint">
                            <router-link :to="{ name: 'query', params: { fingerprint: q.fingerprint } }" class="mono line-clamp-2 text-ink-2 hover:underline">{{ q.sql }}</router-link>
                            <div class="mt-0.5 flex gap-4 text-xs text-muted tabular-nums">
                                <span><strong class="font-medium text-ink-2">{{ q.calls_per_request }}×</strong> per request</span>
                                <span>avg {{ ms(q.avg_ms) }}</span>
                                <span>max {{ ms(q.max_ms) }}</span>
                            </div>
                        </li>
                        <li v-if="!data.queries.length" class="text-sm text-muted">No kept traces in this range.</li>
                    </ul>
                </section>
            </div>
            <p class="text-xs text-muted">Throughput: {{ rpm(data.current.rpm) }} requests/min · Apdex {{ data.current.apdex?.toFixed(2) ?? '–' }} · 4xx {{ percent(data.current.client_error_rate) }} · DB share of time {{ percent(data.current.query_ms_share, 0) }}</p>
        </template>
    </div>
</template>
