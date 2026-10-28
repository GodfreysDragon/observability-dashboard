import { onBeforeUnmount, onMounted, ref } from 'vue';

/** Round tick values (0 / 250 / 500 …) covering [0, max]. */
export function niceTicks(max, count = 4) {
    if (!(max > 0)) return [0, 1];
    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough);
    const ticks = [];
    for (let v = 0; v <= max + step * 0.001; v += step) ticks.push(+v.toFixed(10));
    if (ticks[ticks.length - 1] < max) ticks.push(+(ticks[ticks.length - 1] + step).toFixed(10));
    return ticks;
}

/** Evenly spaced indices for x-axis labels, always including the last one. */
export function tickIndices(length, wanted) {
    if (length <= wanted) return [...Array(length).keys()];
    const step = Math.ceil((length - 1) / (wanted - 1));
    const indices = [];
    for (let i = length - 1; i >= 0; i -= step) indices.unshift(i);
    return indices;
}

/** Reactive width of an element, for SVG charts that fill their card. */
export function useWidth(fallback = 600) {
    const el = ref(null);
    const width = ref(fallback);
    let observer;

    onMounted(() => {
        observer = new ResizeObserver(([entry]) => {
            width.value = Math.max(240, Math.floor(entry.contentRect.width));
        });
        if (el.value) observer.observe(el.value);
    });
    onBeforeUnmount(() => observer?.disconnect());

    return { el, width };
}

/** Platform -> categorical slot. Colour follows the entity everywhere in the app. */
export const PLATFORM_COLORS = {
    ios: 'var(--series-1)',
    android: 'var(--series-2)',
    pc: 'var(--series-3)',
    web: 'var(--series-4)',
};

export const SERIES_COLORS = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)'];

export function platformColor(label) {
    return PLATFORM_COLORS[label] ?? 'var(--series-other)';
}
