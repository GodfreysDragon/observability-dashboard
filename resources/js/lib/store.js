import { reactive, watch } from 'vue';

export const RANGES = [
    { key: '1h', label: '1 hour' },
    { key: '6h', label: '6 hours' },
    { key: '24h', label: '24 hours' },
    { key: '7d', label: '7 days' },
];

function read(key, fallback) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the preference just won't persist.
    }
}

export const state = reactive({
    user: null,
    range: RANGES.some((r) => r.key === read('range', '')) ? read('range', '24h') : '24h',
    /** Auto-refresh period in seconds; 0 = off. */
    refresh: Number(read('refresh', 30)) || 0,
    /** Bumped by the refresh timer; reports include it in their inputs to refetch. */
    tick: 0,
    theme: read('theme', 'system'),
});

export function applyTheme(theme) {
    state.theme = theme;
    write('theme', theme);
    if (theme === 'system') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = theme;
}

let timer = null;

function schedule() {
    clearInterval(timer);
    if (state.refresh > 0) {
        timer = setInterval(() => {
            if (document.visibilityState === 'visible') state.tick++;
        }, state.refresh * 1000);
    }
}

watch(() => state.range, (range) => write('range', range));
watch(
    () => state.refresh,
    (refresh) => {
        write('refresh', String(refresh));
        schedule();
    },
    { immediate: true },
);
