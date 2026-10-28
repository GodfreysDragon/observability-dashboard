<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import ChartCard from '../components/ChartCard.vue';
import TimeSeriesChart from '../components/charts/TimeSeriesChart.vue';
import RouteName from '../components/RouteName.vue';
import StackTrace from '../components/StackTrace.vue';
import { api } from '../lib/api';
import { dateTime, number, shortClass, timeAgo } from '../lib/format';
import { STEPS } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const route = useRoute();
const saving = ref(false);

const { data, loading, error, reload } = useReport(
    () => ({ range: state.range, id: route.params.id }),
    ({ range, id }, signal) => api.get(`/exceptions/${id}`, { range }, { signal }),
);

const group = computed(() => data.value?.group);
const series = computed(() => [{ key: 'count', label: 'Occurrences', color: 'var(--series-2)', points: (data.value?.series ?? []).map((p) => ({ t: p.t, value: p.count })) }]);

async function toggleResolved() {
    saving.value = true;
    try {
        await api.put(`/exceptions/${route.params.id}/resolution`, { resolved: !group.value.resolved_at });
        await reload();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <router-link :to="{ name: 'exceptions' }" class="link text-sm">← Exceptions</router-link>
        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.status === 404 ? 'Unknown exception group.' : error.message }}</div>
        <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
        <template v-else>
            <section class="card space-y-3 p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold break-words text-ink">{{ group.class }}</h2>
                        <p class="mono mt-1 break-words text-ink-2">{{ group.message }}</p>
                    </div>
                    <button type="button" class="btn" :disabled="saving" @click="toggleResolved">{{ group.resolved_at ? 'Reopen' : 'Mark resolved' }}</button>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-5">
                    <div><dt class="text-xs text-muted">Origin</dt><dd class="mono break-all text-ink">{{ group.file }}:{{ group.line }}</dd></div>
                    <div><dt class="text-xs text-muted">First seen</dt><dd class="text-sm text-ink">{{ dateTime(group.first_seen_at) }} <span class="block text-xs text-muted">in {{ group.first_release }}</span></dd></div>
                    <div><dt class="text-xs text-muted">Last seen</dt><dd class="text-sm text-ink">{{ timeAgo(group.last_seen_at) }}</dd></div>
                    <div><dt class="text-xs text-muted">All time</dt><dd class="text-sm font-medium text-ink tabular-nums">{{ number(group.occurrences) }}</dd></div>
                    <div><dt class="text-xs text-muted">Status</dt><dd class="text-sm text-ink">{{ group.resolved_at ? `Resolved ${timeAgo(group.resolved_at)}` : 'Open' }}</dd></div>
                </dl>
            </section>

            <ChartCard :title="`Occurrences (${number(data.count)} in range)`" :loading="loading" :table-toggle="false">
                <TimeSeriesChart :series="series" :step="STEPS[state.range]" bars :format="(v) => number(v)" :height="170" aria-label="Exception occurrences over time" />
            </ChartCard>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
                <section class="card min-w-0 space-y-3 p-4 sm:p-5 lg:col-span-3">
                    <h2 class="text-sm font-semibold text-ink">Latest stack trace</h2>
                    <StackTrace :frames="data.trace" />
                </section>
                <div class="space-y-6 lg:col-span-2">
                    <section class="card p-4 sm:p-5">
                        <h2 class="mb-3 text-sm font-semibold text-ink">Where it happens</h2>
                        <ul class="space-y-2 text-sm">
                            <li v-for="o in data.origins" :key="o.origin" class="flex items-center justify-between gap-3">
                                <RouteName v-if="o.origin.includes(' /')" :route="o.origin" />
                                <span v-else class="mono text-ink">{{ shortClass(o.origin) }}</span>
                                <span class="text-ink-2 tabular-nums">{{ number(o.count) }}</span>
                            </li>
                        </ul>
                    </section>
                    <section class="card p-4 sm:p-5">
                        <h2 class="mb-3 text-sm font-semibold text-ink">By release</h2>
                        <ul class="space-y-2 text-sm">
                            <li v-for="r in data.releases" :key="r.release" class="flex items-center justify-between gap-3">
                                <span class="mono text-ink">{{ r.release }}</span>
                                <span class="text-ink-2 tabular-nums">{{ number(r.count) }}</span>
                            </li>
                        </ul>
                        <p class="mt-3 text-xs text-muted">Stored occurrences; at most 20 per group per minute are kept, every one is counted.</p>
                    </section>
                </div>
            </div>

            <section class="card overflow-x-auto p-2 sm:p-3">
                <h2 class="px-3 pt-2 pb-1 text-sm font-semibold text-ink">Recent occurrences</h2>
                <table class="data-table">
                    <thead><tr><th>When</th><th>In</th><th>Release</th></tr></thead>
                    <tbody>
                        <tr v-for="o in data.occurrences" :key="o.id">
                            <td class="whitespace-nowrap">
                                <router-link v-if="o.request_id" :to="{ name: 'trace', params: { kind: 'requests', id: o.request_id } }" class="link">{{ dateTime(o.occurred_at) }}</router-link>
                                <router-link v-else-if="o.job_run_id" :to="{ name: 'trace', params: { kind: 'jobs', id: o.job_run_id } }" class="link">{{ dateTime(o.occurred_at) }}</router-link>
                                <span v-else>{{ dateTime(o.occurred_at) }}</span>
                            </td>
                            <td class="max-w-96">
                                <span v-if="o.route" class="flex flex-col"><RouteName :route="o.route" /><span class="mono truncate text-muted">{{ o.uri }}</span></span>
                                <span v-else-if="o.job" class="mono">{{ shortClass(o.job) }} <span class="text-muted">on {{ o.queue }}</span></span>
                                <span v-else class="text-muted">outside a request or job</span>
                            </td>
                            <td class="mono text-ink-2">{{ o.release }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
