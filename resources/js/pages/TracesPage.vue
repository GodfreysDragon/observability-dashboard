<script setup>
import { reactive, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import RouteName from '../components/RouteName.vue';
import { api } from '../lib/api';
import { dateTime, ms, REASONS } from '../lib/format';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const route = useRoute();
const filters = reactive({
    route: typeof route.query.route === 'string' ? route.query.route : '',
    status: '',
    reason: typeof route.query.reason === 'string' ? route.query.reason : '',
    min_ms: '',
});

const routes = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/routes', params, { signal }),
    { live: false },
);

const rows = shallowRef([]);
const cursor = ref(null);
const loadingMore = ref(false);

const { data, loading, error } = useReport(
    () => ({ range: state.range, ...filters }),
    (params, signal) => api.get('/traces', params, { signal }),
);

watch(data, (page) => {
    rows.value = page?.data ?? [];
    cursor.value = page?.next_cursor ?? null;
});

async function loadMore() {
    loadingMore.value = true;
    try {
        const page = await api.get('/traces', { range: state.range, ...filters, cursor: cursor.value });
        rows.value = [...rows.value, ...page.data];
        cursor.value = page.next_cursor;
    } finally {
        loadingMore.value = false;
    }
}

function statusTone(status) {
    if (status >= 500) return 'text-bad font-semibold';
    if (status >= 400) return 'text-ink-2';
    return 'text-muted';
}
</script>

<template>
    <div class="space-y-4">
        <p class="max-w-3xl text-sm text-ink-2">
            Full traces are kept by <strong class="font-medium text-ink">tail-based sampling</strong>: decided after the request finishes, so every error,
            exception, slow request, slow query and suspected N+1 is kept, plus a random sample of the rest. Rollups still count every request.
        </p>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent>
            <label class="flex min-w-0 flex-col gap-1">
                <span class="label">Route</span>
                <select v-model="filters.route" class="field max-w-[19rem]">
                    <option value="">All routes</option>
                    <option v-for="r in routes.data.value?.data ?? []" :key="r.route" :value="r.route">{{ r.route }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="label">Status</span>
                <select v-model="filters.status" class="field">
                    <option value="">Any</option>
                    <option value="2xx">2xx</option>
                    <option value="4xx">4xx</option>
                    <option value="5xx">5xx</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="label">Kept because</span>
                <select v-model="filters.reason" class="field">
                    <option value="">Any reason</option>
                    <option v-for="(label, key) in REASONS" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="label">Slower than (ms)</span>
                <input v-model.lazy="filters.min_ms" type="number" min="0" step="50" class="field w-32" placeholder="e.g. 500" />
            </label>
        </form>

        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <section v-else class="card overflow-x-auto p-2 transition-opacity sm:p-3" :class="loading ? 'opacity-60' : ''">
            <table class="data-table">
                <thead>
                    <tr><th>When</th><th>Route</th><th class="text-right">Status</th><th class="text-right">Duration</th><th class="text-right">Queries</th><th>Kept because</th><th>Release</th></tr>
                </thead>
                <tbody>
                    <tr v-for="t in rows" :key="t.id" class="hover:bg-surface-2">
                        <td class="whitespace-nowrap"><router-link :to="{ name: 'trace', params: { kind: 'requests', id: t.id } }" class="link">{{ dateTime(t.occurred_at) }}</router-link></td>
                        <td class="max-w-80"><RouteName :route="t.route" /></td>
                        <td class="text-right tabular-nums" :class="statusTone(t.status)">{{ t.status }}</td>
                        <td class="text-right font-medium text-ink">{{ ms(t.duration_ms) }}</td>
                        <td class="text-right">{{ t.query_count }} <span class="text-xs text-muted">· {{ ms(t.query_ms) }}</span></td>
                        <td><span class="badge !py-0">{{ REASONS[t.sample_reason] }}</span></td>
                        <td class="mono text-ink-2">{{ t.release }}</td>
                    </tr>
                    <tr v-if="data && !rows.length"><td colspan="7" class="py-6 text-center text-muted">No kept traces match these filters in this range.</td></tr>
                </tbody>
            </table>
            <div v-if="cursor" class="p-3 text-center">
                <button type="button" class="btn" :disabled="loadingMore" @click="loadMore">{{ loadingMore ? 'Loading…' : 'Load older traces' }}</button>
            </div>
        </section>
    </div>
</template>
