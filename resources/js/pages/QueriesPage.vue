<script setup>
import SortableTable from '../components/SortableTable.vue';
import { api } from '../lib/api';
import { ms, number, percent } from '../lib/format';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/queries', params, { signal }),
);

const columns = [
    { key: 'sql', label: 'Query shape', sortable: false, class: 'w-full min-w-72' },
    { key: 'calls', label: 'Calls', align: 'right' },
    { key: 'total_ms', label: 'Total time', align: 'right' },
    { key: 'avg_ms', label: 'Avg', align: 'right' },
    { key: 'p95', label: 'p95', align: 'right' },
    { key: 'max_ms', label: 'Max', align: 'right' },
    { key: 'n_plus_one', label: 'N+1 traces', align: 'right' },
];
</script>

<template>
    <div class="space-y-4">
        <p class="max-w-3xl text-sm text-ink-2">
            Queries are grouped by <strong class="font-medium text-ink">fingerprint</strong>: the SQL with literals replaced and <span class="mono">IN</span> lists collapsed, so one
            shape covers every execution. Sorted by total database time, which weighs a cheap query run a million times against an expensive one run rarely.
        </p>
        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <section v-else class="card p-2 transition-opacity sm:p-3" :class="loading ? 'opacity-60' : ''">
            <SortableTable :columns="columns" :rows="data?.data ?? []" row-key="fingerprint" initial-sort="total_ms" caption="Query shapes">
                <template #cell-sql="{ row }">
                    <router-link :to="{ name: 'query', params: { fingerprint: row.fingerprint } }" class="group block">
                        <span class="mono line-clamp-2 text-ink group-hover:underline">{{ row.sql }}</span>
                        <span class="mt-1 flex items-center gap-2">
                            <span class="h-1.5 w-20 rounded-full bg-surface-2"><span class="block h-full rounded-full bg-[var(--series-1)]" :style="{ width: `${row.time_share * 100}%` }" /></span>
                            <span class="text-xs text-muted">{{ percent(row.time_share, 1) }} of DB time</span>
                        </span>
                    </router-link>
                </template>
                <template #cell-calls="{ row }">{{ number(row.calls) }}</template>
                <template #cell-total_ms="{ row }"><span class="font-medium text-ink">{{ ms(row.total_ms) }}</span></template>
                <template #cell-avg_ms="{ row }">{{ ms(row.avg_ms) }}</template>
                <template #cell-p95="{ row }">{{ ms(row.p95) }}</template>
                <template #cell-max_ms="{ row }">{{ ms(row.max_ms) }}</template>
                <template #cell-n_plus_one="{ row }">
                    <span v-if="row.n_plus_one" class="badge !py-0"><span aria-hidden="true">⟳</span> {{ number(row.n_plus_one) }}</span>
                    <span v-else class="text-muted">–</span>
                </template>
            </SortableTable>
        </section>
    </div>
</template>
