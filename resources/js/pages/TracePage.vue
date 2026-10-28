<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import QueryWaterfall from '../components/QueryWaterfall.vue';
import RouteName from '../components/RouteName.vue';
import SqlBlock from '../components/SqlBlock.vue';
import StackTrace from '../components/StackTrace.vue';
import { api } from '../lib/api';
import { dateTime, ms, REASONS, shortClass } from '../lib/format';
import { useReport } from '../lib/useReport';

const route = useRoute();
const kind = computed(() => route.params.kind);

const { data, error } = useReport(
    () => ({ kind: kind.value, id: route.params.id }),
    ({ kind, id }, signal) => api.get(`/traces/${kind}/${id}`, {}, { signal }),
    { live: false },
);

const trace = computed(() => data.value?.trace);
const duration = computed(() => (kind.value === 'requests' ? trace.value?.duration_ms : trace.value?.runtime_ms) ?? 0);
const queryMs = computed(() => (data.value?.queries ?? []).reduce((sum, q) => sum + q.duration_ms, 0));

const facts = computed(() => {
    const t = trace.value;
    if (!t) return [];
    const common = [
        ['Kept because', REASONS[t.sample_reason]],
        ['Release', t.release],
    ];
    if (kind.value === 'requests') {
        return [
            ['Status', t.status],
            ['Duration', ms(t.duration_ms)],
            ['Queries', `${t.query_count} · ${ms(t.query_ms)}`],
            ['Peak memory', `${t.memory_mb} MB`],
            ...common,
        ];
    }
    return [
        ['Status', t.status],
        ['Attempt', t.attempt],
        ['Runtime', ms(t.runtime_ms)],
        ['Waited in queue', t.wait_ms === null ? 'n/a (retry)' : ms(t.wait_ms)],
        ['Queries', t.query_count],
        ...common,
    ];
});
</script>

<template>
    <div class="space-y-6">
        <router-link :to="{ name: kind === 'requests' ? 'traces' : 'queues' }" class="link text-sm">← {{ kind === 'requests' ? 'Traces' : 'Queues' }}</router-link>

        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.status === 404 ? 'This trace no longer exists (it may have been pruned).' : error.message }}</div>
        <div v-else-if="!data" class="card p-8 text-center text-sm text-muted">Loading…</div>
        <template v-else>
            <section class="card space-y-4 p-4 sm:p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="min-w-0 text-base">
                        <RouteName v-if="kind === 'requests'" :route="trace.route" />
                        <span v-else class="mono font-medium text-ink">{{ trace.job }} <span class="text-muted">on {{ trace.queue }}</span></span>
                        <div v-if="kind === 'requests'" class="mono mt-1 truncate text-xs text-muted">{{ trace.uri }}</div>
                    </div>
                    <span class="text-sm text-muted">{{ dateTime(trace.occurred_at) }}</span>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="[label, value] in facts" :key="label">
                        <dt class="text-xs text-muted">{{ label }}</dt>
                        <dd class="text-sm font-medium text-ink tabular-nums">{{ value }}</dd>
                    </div>
                </dl>
                <div v-if="duration > 0" class="space-y-1">
                    <div class="flex justify-between text-xs text-muted">
                        <span>Time in database</span>
                        <span class="tabular-nums">{{ ms(queryMs) }} of {{ ms(duration) }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-surface-2"><div class="h-full rounded-full bg-[var(--series-1)]" :style="{ width: `${Math.min(100, (queryMs / duration) * 100)}%` }" /></div>
                </div>
            </section>

            <section v-for="n in data.n_plus_one" :key="n.fingerprint" class="card border-l-4 !border-l-[var(--status-serious)] p-4 sm:p-5" role="note">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-ink"><span aria-hidden="true" class="text-[var(--status-serious)]">▲</span> Likely N+1: the same query ran {{ n.count }} times</h2>
                <p class="mt-1 mb-3 text-sm text-ink-2">
                    Issued from <span class="mono break-all text-ink">{{ n.location ?? 'framework code' }}</span>. Eager-load the relation (<span class="mono">with()</span>) so it becomes one
                    <span class="mono">where … in (…)</span> query.
                </p>
                <SqlBlock :sql="n.sql" />
            </section>

            <section v-for="e in data.exceptions" :key="e.id" class="card space-y-3 p-4 sm:p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-ink">{{ shortClass(e.class) }}</h2>
                    <router-link :to="{ name: 'exception', params: { id: e.group_id } }" class="link text-xs">Exception group →</router-link>
                </div>
                <p class="mono break-words text-ink-2">{{ e.message }}</p>
                <StackTrace :frames="e.trace" />
            </section>

            <section class="card p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-ink">Queries <span class="font-normal text-muted">({{ data.stored_queries }})</span></h2>
                <p class="mt-0.5 mb-3 text-xs text-muted">Bindings are never stored; literals are replaced with <span class="mono">?</span>. Click a row for the full statement and its call site.</p>
                <QueryWaterfall v-if="data.queries.length" :queries="data.queries" :total-ms="duration" />
                <p v-else class="text-sm text-muted">This trace ran no queries.</p>
            </section>
        </template>
    </div>
</template>
