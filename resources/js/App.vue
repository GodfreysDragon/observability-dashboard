<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, setCsrfToken } from './lib/api';
import { applyTheme, RANGES, state } from './lib/store';

const route = useRoute();
const router = useRouter();
const menuOpen = ref(false);

const nav = [
    { name: 'overview', label: 'Overview', icon: 'M3 13h4v8H3zM10 9h4v12h-4zM17 4h4v17h-4z' },
    { name: 'routes', label: 'Routes', icon: 'M4 6h10M4 12h16M4 18h7' },
    { name: 'traces', label: 'Traces', icon: 'M4 5h9M7 10h11M5 15h6M9 20h10' },
    { name: 'queries', label: 'Queries', icon: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3' },
    { name: 'exceptions', label: 'Exceptions', icon: 'M12 3l9.5 17h-19zM12 10v4M12 17v.5' },
    { name: 'queues', label: 'Queues', icon: 'M4 5h16v4H4zM4 10h16v4H4zM4 15h16v4H4z' },
    { name: 'deploys', label: 'Deploys', icon: 'M12 3v12M7 10l5 5 5-5M5 21h14' },
];

const signedIn = computed(() => state.user !== null && !route.meta.guest);
const themeLabel = computed(() => ({ system: 'Auto', light: 'Light', dark: 'Dark' })[state.theme]);
const section = computed(() => route.meta.section ?? route.name);

function cycleTheme() {
    applyTheme({ system: 'light', light: 'dark', dark: 'system' }[state.theme] ?? 'system');
}

async function logout() {
    const { csrf_token } = await api.post('/logout');
    setCsrfToken(csrf_token);
    state.user = null;
    router.push({ name: 'login' });
}
</script>

<template>
    <router-view v-if="!signedIn" />

    <div v-else class="min-h-full lg:grid lg:grid-cols-[13.5rem_1fr]">
        <aside
            class="fixed inset-y-0 left-0 z-30 w-56 -translate-x-full border-r border-line bg-surface transition-transform lg:static lg:w-auto lg:translate-x-0"
            :class="menuOpen ? 'translate-x-0' : ''"
        >
            <div class="flex h-full flex-col p-4 lg:sticky lg:top-0 lg:h-screen">
                <div class="mb-6 px-2">
                    <div class="flex items-center gap-2">
                        <svg viewBox="0 0 24 24" class="h-5 w-5 text-accent" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                            <path d="M3 12h4l3-8 4 16 3-8h4" />
                        </svg>
                        <span class="font-semibold text-ink">Observability</span>
                    </div>
                    <div class="mt-0.5 pl-7 text-xs text-muted">Ledgerline · production</div>
                </div>
                <nav class="flex-1 space-y-0.5" aria-label="Main">
                    <router-link
                        v-for="item in nav"
                        :key="item.name"
                        :to="{ name: item.name }"
                        class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-ink-2 hover:bg-surface-2 hover:text-ink"
                        :class="section === item.name ? '!bg-surface-2 font-medium !text-ink' : ''"
                        :aria-current="section === item.name ? 'page' : undefined"
                        @click="menuOpen = false"
                    >
                        <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
                            <path :d="item.icon" />
                        </svg>
                        {{ item.label }}
                    </router-link>
                </nav>
                <div class="space-y-2 border-t border-line pt-4 text-sm">
                    <button type="button" class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-ink-2 hover:bg-surface-2" @click="cycleTheme">
                        Theme <span class="text-xs text-muted">{{ themeLabel }}</span>
                    </button>
                    <div class="truncate px-2.5 text-xs text-muted">{{ state.user.email }}</div>
                    <button type="button" class="w-full rounded-lg px-2.5 py-1.5 text-left text-ink-2 hover:bg-surface-2" @click="logout">Sign out</button>
                </div>
            </div>
        </aside>
        <div v-if="menuOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="menuOpen = false" />

        <div class="min-w-0">
            <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                    <button type="button" class="btn !px-2 lg:hidden" aria-label="Open menu" @click="menuOpen = true">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <h1 class="mr-auto text-lg font-semibold text-ink">{{ route.meta.title }}</h1>
                    <div v-if="!route.meta.noRange" class="flex rounded-lg border border-line bg-surface p-0.5 text-sm" role="group" aria-label="Time range">
                        <button
                            v-for="r in RANGES"
                            :key="r.key"
                            type="button"
                            class="rounded-md px-2.5 py-1"
                            :class="state.range === r.key ? 'bg-surface-2 font-medium text-ink' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="state.range === r.key"
                            :title="`Last ${r.label}`"
                            @click="state.range = r.key"
                        >
                            {{ r.key }}
                        </button>
                    </div>
                    <label class="flex items-center gap-1.5 text-sm text-ink-2">
                        <span class="sr-only">Auto-refresh</span>
                        <select v-model.number="state.refresh" class="field !py-1" aria-label="Auto-refresh">
                            <option :value="0">Refresh off</option>
                            <option :value="15">Every 15s</option>
                            <option :value="30">Every 30s</option>
                            <option :value="60">Every 1m</option>
                        </select>
                    </label>
                </div>
            </header>
            <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <router-view :key="route.fullPath" />
            </main>
        </div>
    </div>
</template>
