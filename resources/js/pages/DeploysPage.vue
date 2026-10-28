<script setup>
import Delta from '../components/Delta.vue';
import RouteName from '../components/RouteName.vue';
import StatusDot from '../components/StatusDot.vue';
import { api } from '../lib/api';
import { dateTime, ms, number, percent, rpm, seconds, shortClass, timeAgo } from '../lib/format';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => ({}),
    (params, signal) => api.get('/deploys', params, { signal }),
);

const verdicts = {
    regressed: ['critical', 'Regressed'],
    improved: ['ok', 'Improved'],
    neutral: ['neutral', 'No significant change'],
    pending: ['warning', 'Collecting data'],
    insufficient: ['neutral', 'Too close to another deploy'],
};

const metrics = [
    { key: 'p95', label: 'p95 latency', format: ms, change: 'p95', kind: 'relative', threshold: 0.1 },
    { key: 'error_rate', label: 'Server error rate', format: (v) => percent(v, 2), change: 'error_rate', kind: 'points', threshold: 0.001 },
    { key: 'apdex', label: 'Apdex', format: (v) => (v === null ? '–' : v.toFixed(2)), change: 'apdex', kind: 'absolute', threshold: 0.02, upIsGood: true },
    { key: 'queries_per_request', label: 'Queries / request', format: (v) => (v === null ? '–' : v), change: 'queries_per_request', kind: 'relative', threshold: 0.1 },
    { key: 'rpm', label: 'Traffic (req/min)', format: rpm, change: 'rpm', kind: 'relative', threshold: Infinity },
];

const signed = (v) => (v === null ? '–' : `${v > 0 ? '+' : v < 0 ? '−' : '±'}${Math.abs(v).toFixed(2)}`);
</script>

<template>
    <div class="space-y-4">
        <p class="max-w-3xl text-sm text-ink-2">
            Each deploy is judged on its own traffic: equal windows (up to 6 hours) either side of the deploy, never crossing a neighbouring deploy.
            Route-level changes need at least 30 requests on each side. Traffic is shown for context only; it moves with the time of day, so it never decides the verdict.
        </p>
        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
        <p v-else-if="!data.data.length" class="card p-8 text-center text-sm text-muted">No deploys recorded. CI can record one with <span class="mono">php artisan obs:deploy v1.2.3 --commit=…</span></p>

        <article v-for="d in data?.data ?? []" :key="d.id" class="card space-y-4 p-4 transition-opacity sm:p-5" :class="loading ? 'opacity-60' : ''">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex flex-wrap items-baseline gap-2">
                        <span class="mono text-base font-semibold text-ink">{{ d.release }}</span>
                        <span v-if="d.commit" class="mono text-xs text-muted">{{ d.commit }}</span>
                    </h2>
                    <p class="mt-0.5 text-sm text-ink-2">{{ d.notes }}</p>
                    <p class="mt-0.5 text-xs text-muted">{{ dateTime(d.deployed_at) }} ({{ timeAgo(d.deployed_at) }})<span v-if="d.deployed_by"> by {{ d.deployed_by }}</span> · compared over {{ seconds(d.window_s) }} each side</p>
                </div>
                <StatusDot :status="verdicts[d.verdict][0]" :label="verdicts[d.verdict][1]" />
            </header>

            <div v-if="d.before" class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Metric</th><th class="text-right">Before</th><th class="text-right">After</th><th class="text-right">Change</th></tr></thead>
                    <tbody>
                        <tr v-for="m in metrics" :key="m.key">
                            <td>{{ m.label }}</td>
                            <td class="text-right">{{ m.format(d.before[m.key]) }}</td>
                            <td class="text-right font-medium text-ink">{{ m.format(d.after[m.key]) }}</td>
                            <td class="text-right">
                                <Delta :value="d.changes[m.change]" :kind="m.kind" :threshold="m.threshold" :up-is-good="!!m.upIsGood" :format="m.kind === 'absolute' ? signed : null" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="d.routes.length">
                <h3 class="mb-2 text-xs font-semibold tracking-wide text-ink-2 uppercase">Routes that changed</h3>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Route</th><th class="text-right">p95 before → after</th><th class="text-right">Queries/req before → after</th><th class="text-right">5xx before → after</th></tr></thead>
                        <tbody>
                            <tr v-for="r in d.routes" :key="r.route">
                                <td><RouteName :route="r.route" /></td>
                                <td class="text-right">{{ ms(r.p95_before) }} → <span class="font-medium text-ink">{{ ms(r.p95_after) }}</span> <Delta :value="r.p95_change" :threshold="0.1" /></td>
                                <td class="text-right">{{ r.queries_before }} → <span class="font-medium text-ink">{{ r.queries_after }}</span> <Delta :value="r.queries_change" :threshold="0.1" /></td>
                                <td class="text-right">{{ percent(r.error_rate_before, 2) }} → <span class="font-medium text-ink">{{ percent(r.error_rate_after, 2) }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="d.new_exceptions.length">
                <h3 class="mb-2 text-xs font-semibold tracking-wide text-ink-2 uppercase">New exceptions first seen in this release</h3>
                <ul class="space-y-1 text-sm">
                    <li v-for="e in d.new_exceptions" :key="e.id">
                        <router-link :to="{ name: 'exception', params: { id: e.id } }" class="link">{{ shortClass(e.class) }}</router-link>
                        <span class="mono text-ink-2"> {{ e.message }}</span>
                        <span class="text-muted"> · {{ number(e.occurrences) }} so far</span>
                    </li>
                </ul>
            </div>
        </article>
    </div>
</template>
