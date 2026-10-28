/** Build TimeSeriesChart series from report points: specs = [{ key, label, color, short? }]. */
export function toSeries(points, specs) {
    return specs.map((spec) => ({ ...spec, points: (points ?? []).map((p) => ({ t: p.t, value: p[spec.key] ?? null })) }));
}

export const LATENCY_SERIES = [
    { key: 'p50', label: 'p50', color: 'var(--series-1)' },
    { key: 'p95', label: 'p95', color: 'var(--series-2)' },
    { key: 'p99', label: 'p99', color: 'var(--series-3)' },
];

export function deployMarkers(deploys) {
    return (deploys ?? []).map((d) => ({ t: d.t, label: d.release }));
}

/** Step of a range key (mirrors TimeRange::PRESETS). */
export const STEPS = { '1h': 60, '6h': 300, '24h': 900, '7d': 3600 };

export function previousLabel(range) {
    return { '1h': 'previous hour', '6h': 'previous 6h', '24h': 'previous 24h', '7d': 'previous 7 days' }[range];
}
