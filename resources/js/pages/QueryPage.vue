<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ChartCard from '../components/ChartCard.vue';
import TimeSeriesChart from '../components/charts/TimeSeriesChart.vue';
import RouteName from '../components/RouteName.vue';
import SqlBlock from '../components/SqlBlock.vue';
import { api } from '../lib/api';
import { dateTime, ms, msTick, number, rpm, shortClass } from '../lib/format';
import { LATENCY_SERIES, STEPS, toSeries } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const route = useRoute();

const { data, loading, error } = useReport(
    () => ({ range: state.range, fingerprint: route.params.fingerprint }),
    ({ range, fingerprint }, signal) => api.get(`/queries/${fingerprint}`, { range }, { signal }),
);

const step = computed(() => STEPS[state.range]);
const facts = computed(() => {
    const d = data.value;
    if (!d) return [];
    return [
        ['Calls', number(d.calls)],
        ['Total time', ms(d.total_ms)],
        ['Average', ms(d.avg_ms)],
        ['p95', ms(d.p95)],
        ['Max', ms(d.max_ms)],
        ['In N+1 traces', number(d.n_plus_one)],
    ];
});
</script>

<template>
    <div class="space-y-6">
        <router-link :to="{ name: 'queries' }" class="link text-sm">← Queries</router-link>
        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.status === 404 ? 'Unknown query shape.' : error.message }}</div>
        <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
        <template v-else>
            <section class="card space-y-4 p-4 sm:p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2 text-xs text-muted">
                    <span>Fingerprint <span class="mono text-ink-2">{{ data.fingerprint }}</span></span>
                    <span>First seen {{ dateTime(data.first_seen_at) }}</span>
                </div>
                <SqlBlock :sql="data.sql" />
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="[label, value] in facts" :key="label">
                        <dt class="text-xs text-muted">{{ label }}</dt>
                        <dd class="text-sm font-medium text-ink tabular-nums">{{ value }}</dd>
                    </div>
                </dl>
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <ChartCard title="Latency" :loading="loading" :table-toggle="false">
                    <TimeSeriesChart :series="toSeries(data.series, LATENCY_SERIES)" :step="step" :format="ms" :tick-format="msTick" :height="200" aria-label="Query latency percentiles" />
                </ChartCard>
                <ChartCard title="Executions" subtitle="Calls per minute" :loading="loading" :table-toggle="false">
                    <TimeSeriesChart :series="toSeries(data.series, [{ key: 'calls_per_minute', label: 'Calls / min', color: 'var(--series-1)' }])" :step="step" :format="rpm" area :height="200" aria-label="Query executions per minute" />
                </ChartCard>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="card min-w-0 p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-ink">Call sites</h2>
                    <p class="mt-0.5 mb-3 text-xs text-muted">Routes or jobs and the application line that issued the query, from kept traces.</p>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Caller</th><th class="text-right">Calls</th><th class="text-right">Per trace</th></tr></thead>
                            <tbody>
                                <tr v-for="c in data.callers" :key="`${c.caller}${c.location}`">
                                    <td>
                                        <RouteName v-if="c.caller && c.caller.includes(' /')" :route="c.caller" />
                                        <span v-else class="mono text-ink">{{ shortClass(c.caller) }}</span>
                                        <div class="mono text-muted">{{ c.location ?? 'framework (route model binding / queue)' }}</div>
                                    </td>
                                    <td class="text-right">{{ number(c.calls) }}</td>
                                    <td class="text-right">{{ (c.calls / c.traces).toFixed(1) }}×</td>
                                </tr>
                                <tr v-if="!data.callers.length"><td colspan="3" class="text-center text-muted">No kept traces include this query in this range.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="card min-w-0 p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-ink">Slowest executions</h2>
                    <p class="mt-0.5 mb-3 text-xs text-muted">Each links to the trace it ran in.</p>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>When</th><th>In</th><th class="text-right">Duration</th></tr></thead>
                            <tbody>
                                <tr v-for="(s, i) in data.slowest" :key="i">
                                    <td class="whitespace-nowrap">
                                        <router-link
                                            :to="{ name: 'trace', params: s.request_id ? { kind: 'requests', id: s.request_id } : { kind: 'jobs', id: s.job_run_id } }"
                                            class="link"
                                        >{{ dateTime(s.occurred_at) }}</router-link>
                                    </td>
                                    <td class="max-w-64 truncate"><RouteName v-if="s.route" :route="s.route" /><span v-else class="mono">{{ shortClass(s.job) }}</span></td>
                                    <td class="text-right font-medium text-ink">{{ ms(s.duration_ms) }}</td>
                                </tr>
                                <tr v-if="!data.slowest.length"><td colspan="3" class="text-center text-muted">None kept in this range.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
