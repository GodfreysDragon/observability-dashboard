<script setup>
import { computed, ref } from 'vue';
import Sparkline from '../components/charts/Sparkline.vue';
import { api } from '../lib/api';
import { dateTime, number, shortClass, timeAgo } from '../lib/format';
import { previousLabel } from '../lib/series';
import { state } from '../lib/store';
import { useReport } from '../lib/useReport';

const showResolved = ref(false);

const { data, loading, error } = useReport(
    () => ({ range: state.range }),
    (params, signal) => api.get('/exceptions', params, { signal }),
);

const groups = computed(() => (data.value?.data ?? []).filter((g) => showResolved.value || !g.resolved_at));
const resolvedCount = computed(() => (data.value?.data ?? []).filter((g) => g.resolved_at).length);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-3xl text-sm text-ink-2">
                Grouped by exception class and the first application frame, not by message, so ids in messages don't split one bug into many groups.
                A resolved group reopens by itself if it happens again.
            </p>
            <label v-if="resolvedCount" class="flex items-center gap-2 text-sm text-ink-2">
                <input v-model="showResolved" type="checkbox" class="accent-[var(--accent)]" />
                Show {{ resolvedCount }} resolved
            </label>
        </div>

        <div v-if="error" class="card p-6 text-sm text-bad" role="alert">{{ error.message }}</div>
        <section v-else class="card divide-y divide-line transition-opacity" :class="loading ? 'opacity-60' : ''">
            <router-link
                v-for="g in groups"
                :key="g.id"
                :to="{ name: 'exception', params: { id: g.id } }"
                class="flex flex-col gap-3 p-4 hover:bg-surface-2 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-ink">{{ shortClass(g.class) }}</span>
                        <span v-if="g.is_new" class="badge !py-0">new · first in {{ g.first_release }}</span>
                        <span v-if="g.resolved_at" class="badge !py-0">resolved</span>
                    </div>
                    <p class="mono mt-0.5 truncate text-ink-2">{{ g.message }}</p>
                    <p class="mt-0.5 text-xs text-muted">
                        <span class="mono break-all">{{ g.location }}</span> · first seen {{ dateTime(g.first_seen_at) }} · last {{ timeAgo(g.last_seen_at) }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Sparkline :values="g.trend" :width="120" :height="28" color="var(--series-2)" :label="`${shortClass(g.class)} occurrences over time`" />
                    <div class="w-24 text-right">
                        <div class="text-lg font-semibold text-ink tabular-nums">{{ number(g.count) }}</div>
                        <div class="text-xs text-muted">{{ number(g.previous_count) }} in {{ previousLabel(state.range) }}</div>
                    </div>
                </div>
            </router-link>
            <p v-if="data && !groups.length" class="p-8 text-center text-sm text-muted">No exceptions in this range.</p>
        </section>
    </div>
</template>
