import { ref, shallowRef, watch } from 'vue';
import { state } from './store';

/**
 * Fetch a report whenever its inputs change, and again silently on each auto-refresh
 * tick. While an input-driven refetch is in flight the previous data stays on screen
 * (pages dim it) instead of flashing a skeleton; stale responses are dropped.
 *
 * @param {() => object|null} source  reactive inputs; return null to skip fetching
 * @param {(inputs: object, signal: AbortSignal) => Promise<any>} fetcher
 * @param {{ live?: boolean }} options  live: false opts out of auto-refresh
 */
export function useReport(source, fetcher, { live = true } = {}) {
    const data = shallowRef(null);
    const loading = ref(false);
    const error = ref(null);
    const updatedAt = ref(null);
    let controller = null;

    async function load({ quiet = false } = {}) {
        const inputs = source();
        if (!inputs) return;

        controller?.abort();
        controller = new AbortController();
        const current = controller;

        if (!quiet) loading.value = true;
        error.value = null;

        try {
            const result = await fetcher(inputs, current.signal);
            if (current === controller) {
                data.value = result;
                updatedAt.value = new Date();
            }
        } catch (e) {
            if (e.name !== 'AbortError' && current === controller) error.value = e;
        } finally {
            if (current === controller) loading.value = false;
        }
    }

    watch(() => JSON.stringify(source()), () => load(), { immediate: true });

    if (live) {
        watch(
            () => state.tick,
            () => {
                if (!loading.value) load({ quiet: true });
            },
        );
    }

    return { data, loading, error, updatedAt, reload: load };
}
