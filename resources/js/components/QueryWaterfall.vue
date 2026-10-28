<script setup>
import { computed, ref } from 'vue';
import SqlBlock from './SqlBlock.vue';
import { ms } from '../lib/format';

/**
 * Query timeline for one trace: each query as a bar placed at its offset within the
 * request. Repeated N+1 queries are flagged; clicking a row expands the SQL.
 */
const props = defineProps({
    queries: { type: Array, required: true },
    totalMs: { type: Number, required: true },
});

const open = ref(null);
const total = computed(() => Math.max(props.totalMs, ...props.queries.map((q) => q.offset_ms + q.duration_ms)));
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => ({ f, label: ms(total.value * f) })));

function bar(q) {
    return {
        left: `${(q.offset_ms / total.value) * 100}%`,
        width: `max(2px, ${(q.duration_ms / total.value) * 100}%)`,
    };
}
</script>

<template>
    <div class="min-w-0">
        <div class="mb-1 hidden grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_4.5rem] gap-3 text-xs text-muted sm:grid">
            <span>Query</span>
            <span class="relative h-4">
                <span v-for="t in ticks" :key="t.f" class="absolute -translate-x-1/2 tabular-nums" :style="{ left: `${t.f * 100}%`, transform: t.f === 1 ? 'translateX(-100%)' : t.f === 0 ? 'none' : undefined }">{{ t.label }}</span>
            </span>
            <span class="text-right">Duration</span>
        </div>
        <ol class="divide-y divide-line border-y border-line">
            <li v-for="q in queries" :key="q.id">
                <button
                    type="button"
                    class="grid w-full grid-cols-[minmax(0,1fr)_4.5rem] items-center gap-x-3 gap-y-1 py-1.5 text-left hover:bg-surface-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_4.5rem]"
                    :aria-expanded="open === q.id"
                    @click="open = open === q.id ? null : q.id"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <span v-if="q.n_plus_one" class="badge shrink-0 !border-status-serious !px-1.5 !py-0 text-[10px]" :title="`Executed ${q.repeats}× in this trace`">
                            <span aria-hidden="true">⟳</span> N+1 ×{{ q.repeats }}
                        </span>
                        <span class="mono truncate text-ink-2">{{ q.sql }}</span>
                    </span>
                    <span class="relative col-span-2 h-3 rounded-sm bg-surface-2 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                        <span
                            class="absolute inset-y-0 rounded-sm"
                            :style="{ ...bar(q), background: q.duration_ms >= 100 ? 'var(--series-2)' : 'var(--series-1)' }"
                        />
                    </span>
                    <span class="row-start-1 text-right text-xs font-medium text-ink tabular-nums sm:col-start-3">{{ ms(q.duration_ms) }}</span>
                </button>
                <div v-if="open === q.id" class="space-y-2 pb-3">
                    <SqlBlock :sql="q.sql" />
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                        <span>at +{{ ms(q.offset_ms) }}</span>
                        <span v-if="q.location">from <span class="mono text-ink-2">{{ q.location }}</span></span>
                        <router-link :to="{ name: 'query', params: { fingerprint: q.fingerprint } }" class="link">Query shape {{ q.fingerprint }} →</router-link>
                    </div>
                </div>
            </li>
        </ol>
        <p class="mt-2 flex flex-wrap gap-x-4 text-xs text-muted">
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2 w-3 rounded-sm bg-[var(--series-1)]" /> query</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2 w-3 rounded-sm bg-[var(--series-2)]" /> slow query (≥ 100 ms)</span>
        </p>
    </div>
</template>
