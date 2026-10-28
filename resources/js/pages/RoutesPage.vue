<script setup>
import Delta from '../components/Delta.vue';
import RouteName from '../components/RouteName.vue';
import SortableTable from '../components/SortableTable.vue';
import { api } from '../lib/api';
import { ms, number, percent, rpm } from '../lib/format';
import { previousLabel } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/routes', params, { signal }),
);

const columns = [
    { key: 'route', label: 'Route' },
    { key: 'count', label: 'Requests', align: 'right' },
    { key: 'rpm', label: 'Req/min', align: 'right' },
    { key: 'p50', label: 'p50', align: 'right' },
    { key: 'p95', label: 'p95', align: 'right' },
    { key: 'p99', label: 'p99', align: 'right' },
    { key: 'error_rate', label: '5xx', align: 'right' },
    { key: 'client_error_rate', label: '4xx', align: 'right' },
    { key: 'apdex', label: 'Apdex', align: 'right' },
    { key: 'queries_per_request', label: 'Queries/req', align: 'right' },
    { key: 'time_share', label: 'Time share', align: 'right' },
];
</script>

<template>
    <div class="space-y-4">
        <p class="max-w-3xl text-sm text-ink-2">
            Every route pattern in the selected window, from rollups covering 100% of traffic. Percentiles are estimated from log-bucket histograms
            (each bucket spans 25%). <strong class="font-medium text-ink">Time share</strong> is the route's slice of total server time, the best single
            ranking for "what to optimise first".
        </p>
        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <section v-else class="card p-2 transition-opacity sm:p-3" :class="loading ? 'opacity-60' : ''">
            <SortableTable :columns="columns" :rows="data?.data ?? []" row-key="route" initial-sort="time_share" caption="Routes">
                <template #cell-route="{ row }">
                    <span class="flex items-center gap-2">
                        <RouteName :route="row.route" />
                        <span v-if="row.n_plus_one" class="badge !py-0 text-[10px]" :title="`${number(row.n_plus_one)} requests looked like an N+1`">N+1</span>
                    </span>
                </template>
                <template #cell-count="{ row }">{{ number(row.count) }}</template>
                <template #cell-rpm="{ row }">{{ rpm(row.rpm) }}</template>
                <template #cell-p50="{ row }">{{ ms(row.p50) }}</template>
                <template #cell-p95="{ row }">
                    <span class="font-medium text-ink">{{ ms(row.p95) }}</span>
                    <span class="block"><Delta :value="row.p95_change" :threshold="0.1" :title="`vs ${previousLabel(state.range)}`" /></span>
                </template>
                <template #cell-p99="{ row }">{{ ms(row.p99) }}</template>
                <template #cell-error_rate="{ row }">{{ percent(row.error_rate, 2) }}</template>
                <template #cell-client_error_rate="{ row }">{{ percent(row.client_error_rate, 1) }}</template>
                <template #cell-apdex="{ row }">{{ row.apdex?.toFixed(2) ?? '–' }}</template>
                <template #cell-queries_per_request="{ row }">{{ row.queries_per_request ?? '–' }}</template>
                <template #cell-time_share="{ row }">
                    <span class="inline-flex items-center gap-2">
                        <span class="hidden h-1.5 w-12 rounded-full bg-surface-2 sm:block"><span class="block h-full rounded-full bg-[var(--series-1)]" :style="{ width: `${row.time_share * 100}%` }" /></span>
                        {{ percent(row.time_share, 1) }}
                    </span>
                </template>
            </SortableTable>
        </section>
        <p class="text-xs text-muted">p95 change is against the {{ previousLabel(state.range) }}; routes with fewer than 20 requests there show no change.</p>
    </div>
</template>
