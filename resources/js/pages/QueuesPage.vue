<script setup>
import { computed } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import TimeSeriesChart from '../components/charts/TimeSeriesChart.vue';
import SortableTable from '../components/SortableTable.vue';
import StatusDot from '../components/StatusDot.vue';
import { api } from '../lib/api';
import { dateTime, ms, msTick, number, percent, rpm, seconds, shortClass, timeAgo } from '../lib/format';
import { STEPS } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/queues', params, { signal }),
);

/** Each queue keeps its colour on every chart (fixed order, never by rank). */
const QUEUE_COLORS = { mail: 'var(--series-1)', pdf: 'var(--series-2)', webhooks: 'var(--series-3)', default: 'var(--series-4)' };
const colorOf = (queue) => QUEUE_COLORS[queue] ?? 'var(--series-other)';
const step = computed(() => STEPS[state.range]);

function seriesFrom(source, key, transform = (v) => v) {
    return Object.entries(source ?? {}).map(([queue, points]) => ({
        key: queue,
        label: queue,
        color: colorOf(queue),
        points: points.map((p) => ({ t: p.t, value: p[key] === null ? null : transform(p[key]) })),
    }));
}

const throughput = computed(() => seriesFrom(data.value?.series.throughput, 'jobs_per_minute'));
const depth = computed(() => seriesFrom(data.value?.series.depth, 'pending'));
const wait = computed(() => seriesFrom(data.value?.series.throughput, 'wait_avg'));

function health(q) {
    if (q.oldest_pending_s > 600 || q.failure_rate > 0.1) return ['critical', 'Unhealthy'];
    if (q.oldest_pending_s > 60 || q.failure_rate > 0.02) return ['warning', 'Degraded'];
    return ['ok', 'Healthy'];
}

const jobColumns = [
    { key: 'job', label: 'Job' },
    { key: 'processed', label: 'Processed', align: 'right' },
    { key: 'failed', label: 'Failed', align: 'right' },
    { key: 'released', label: 'Retried', align: 'right' },
    { key: 'p50', label: 'Runtime p50', align: 'right' },
    { key: 'p95', label: 'Runtime p95', align: 'right' },
    { key: 'wait_avg', label: 'Avg wait', align: 'right' },
    { key: 'wait_max', label: 'Max wait', align: 'right' },
];
</script>

<template>
    <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
    <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
    <div v-else class="space-y-6 transition-opacity" :class="loading ? 'opacity-60' : ''">
        <section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="Queues">
            <div v-for="q in data.queues" :key="q.queue" class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 font-semibold text-ink">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colorOf(q.queue) }" aria-hidden="true" />
                        {{ q.queue }}
                    </h2>
                    <StatusDot :status="health(q)[0]" :label="health(q)[1]" />
                </div>
                <dl class="mt-3 grid grid-cols-3 gap-3">
                    <div><dt class="text-xs text-muted">Jobs / min</dt><dd class="text-lg font-semibold text-ink tabular-nums">{{ rpm(q.jobs_per_minute) }}</dd></div>
                    <div><dt class="text-xs text-muted">Failure rate</dt><dd class="text-lg font-semibold text-ink tabular-nums">{{ percent(q.failure_rate, 1) }}</dd></div>
                    <div><dt class="text-xs text-muted">Runtime p95</dt><dd class="text-lg font-semibold text-ink tabular-nums">{{ ms(q.p95) }}</dd></div>
                    <div><dt class="text-xs text-muted">Pending now</dt><dd class="text-sm font-medium text-ink tabular-nums">{{ number(q.pending) }}</dd></div>
                    <div><dt class="text-xs text-muted">Oldest pending</dt><dd class="text-sm font-medium text-ink tabular-nums">{{ q.oldest_pending_s === null ? '–' : seconds(q.oldest_pending_s) }}</dd></div>
                    <div><dt class="text-xs text-muted">Max wait</dt><dd class="text-sm font-medium text-ink tabular-nums">{{ ms(q.wait_max) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-muted">{{ number(q.processed) }} processed · {{ number(q.failed) }} failed · {{ number(q.released) }} retried<span v-if="q.snapshot_at"> · depth sampled {{ timeAgo(q.snapshot_at) }}</span></p>
            </div>
        </section>

        <ChartCard title="Backlog" subtitle="Pending jobs per queue, sampled every minute (max per interval)" :loading="loading" :table-toggle="false">
            <TimeSeriesChart :series="depth" :step="step" :format="(v) => number(v)" :height="210" aria-label="Queue backlog" />
        </ChartCard>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <ChartCard title="Throughput" subtitle="Job attempts per minute" :loading="loading" :table-toggle="false">
                <TimeSeriesChart :series="throughput" :step="step" :format="rpm" :height="190" aria-label="Queue throughput" />
            </ChartCard>
            <ChartCard title="Time waiting in queue" subtitle="Average wait before a first attempt starts" :loading="loading" :table-toggle="false">
                <TimeSeriesChart :series="wait" :step="step" :format="ms" :tick-format="msTick" :height="190" aria-label="Queue wait time" />
            </ChartCard>
        </div>

        <section class="card p-2 sm:p-3">
            <h2 class="px-3 pt-2 pb-1 text-sm font-semibold text-ink">Jobs</h2>
            <SortableTable :columns="jobColumns" :rows="data.jobs" :row-key="(r) => `${r.queue}${r.job}`" initial-sort="processed" caption="Jobs">
                <template #cell-job="{ row }"><span class="mono text-ink">{{ shortClass(row.job) }}</span> <span class="text-xs text-muted">on {{ row.queue }}</span></template>
                <template #cell-processed="{ row }">{{ number(row.processed) }}</template>
                <template #cell-failed="{ row }">{{ number(row.failed) }} <span class="block text-xs text-muted">{{ percent(row.failure_rate, 1) }}</span></template>
                <template #cell-released="{ row }">{{ number(row.released) }}</template>
                <template #cell-p50="{ row }">{{ ms(row.p50) }}</template>
                <template #cell-p95="{ row }"><span class="font-medium text-ink">{{ ms(row.p95) }}</span></template>
                <template #cell-wait_avg="{ row }">{{ ms(row.wait_avg) }}</template>
                <template #cell-wait_max="{ row }">{{ ms(row.wait_max) }}</template>
            </SortableTable>
        </section>

        <section class="card overflow-x-auto p-2 sm:p-3">
            <h2 class="px-3 pt-2 pb-1 text-sm font-semibold text-ink">Recent failures and retries</h2>
            <table class="data-table">
                <thead><tr><th>When</th><th>Job</th><th>Outcome</th><th class="text-right">Attempt</th><th class="text-right">Runtime</th><th>Exception</th></tr></thead>
                <tbody>
                    <tr v-for="f in data.failures" :key="f.id" class="hover:bg-surface-2">
                        <td class="whitespace-nowrap"><router-link :to="{ name: 'trace', params: { kind: 'jobs', id: f.id } }" class="link">{{ dateTime(f.occurred_at) }}</router-link></td>
                        <td class="mono">{{ shortClass(f.job) }}</td>
                        <td><StatusDot :status="f.status === 'failed' ? 'critical' : 'warning'" :label="f.status === 'failed' ? 'Failed' : 'Released for retry'" /></td>
                        <td class="text-right">{{ f.attempt }}</td>
                        <td class="text-right">{{ ms(f.runtime_ms) }}</td>
                        <td class="max-w-80">
                            <router-link v-if="f.group_id" :to="{ name: 'exception', params: { id: f.group_id } }" class="link mono block truncate">{{ shortClass(f.exception_class) }}: {{ f.exception_message }}</router-link>
                        </td>
                    </tr>
                    <tr v-if="!data.failures.length"><td colspan="6" class="py-6 text-center text-muted">No failed or retried jobs in this range.</td></tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
