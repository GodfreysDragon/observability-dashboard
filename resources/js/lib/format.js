const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const whole = new Intl.NumberFormat('en');

export const isMissing = (value) => value === null || value === undefined || Number.isNaN(value);

export function number(value, { exact = false } = {}) {
    if (isMissing(value)) return '–';
    return !exact && Math.abs(value) >= 10_000 ? compact.format(value) : whole.format(Math.round(value * 10) / 10);
}

/** Milliseconds with a unit that fits the magnitude: 0.42 ms, 38 ms, 1.24 s, 3.2 min. */
export function ms(value) {
    if (isMissing(value)) return '–';
    if (value < 1) return `${value.toFixed(2)} ms`;
    if (value < 10) return `${value.toFixed(1)} ms`;
    if (value < 1000) return `${Math.round(value)} ms`;
    if (value < 60_000) return `${(value / 1000).toFixed(value < 10_000 ? 2 : 1)} s`;
    return `${(value / 60_000).toFixed(1)} min`;
}

/** Axis ticks: shorter than ms(). */
export function msTick(value) {
    if (value === 0) return '0';
    if (value < 1000) return `${Math.round(value)}ms`;
    return `${+(value / 1000).toFixed(1)}s`;
}

export function seconds(value) {
    if (isMissing(value)) return '–';
    if (value < 60) return `${Math.round(value)}s`;
    if (value < 3600) return `${Math.floor(value / 60)}m ${Math.round(value % 60)}s`;
    return `${Math.floor(value / 3600)}h ${Math.round((value % 3600) / 60)}m`;
}

export function percent(value, digits = 1) {
    if (isMissing(value)) return '–';
    const scaled = value * 100;
    // Small rates matter here (0.08% errors), so keep precision below 1%.
    const places = Math.abs(scaled) > 0 && Math.abs(scaled) < 1 ? Math.max(digits, 2) : digits;
    return `${scaled.toFixed(places).replace(/\.?0+$/, '')}%`;
}

export function signedPercent(value) {
    if (isMissing(value)) return null;
    const sign = value > 0 ? '+' : value < 0 ? '−' : '±';
    return `${sign}${Math.abs(value * 100).toFixed(Math.abs(value) < 0.1 ? 1 : 0)}%`;
}

/** Change in a rate, in percentage points. */
export function signedPoints(value) {
    if (isMissing(value)) return null;
    const sign = value > 0 ? '+' : value < 0 ? '−' : '±';
    return `${sign}${Math.abs(value * 100).toFixed(2)} pp`;
}

export function rpm(value) {
    if (isMissing(value)) return '–';
    return value < 10 ? value.toFixed(1) : number(Math.round(value));
}

const timeFmt = new Intl.DateTimeFormat('en', { hour: '2-digit', minute: '2-digit', hour12: false });
const dayFmt = new Intl.DateTimeFormat('en', { weekday: 'short', day: 'numeric' });
const fullFmt = new Intl.DateTimeFormat('en', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });

/** Axis label for a unix timestamp; days when the range spans days. */
export function timeTick(t, step = 60) {
    const date = new Date(t * 1000);
    if (step >= 3600 && date.getHours() === 0 && date.getMinutes() === 0) return dayFmt.format(date);
    return step >= 3600 ? `${dayFmt.format(date)} ${timeFmt.format(date)}` : timeFmt.format(date);
}

export function timeTitle(t) {
    return fullFmt.format(new Date(t * 1000));
}

/** DB timestamps are UTC "Y-m-d H:i:s(.v)". */
export function parseUtc(value) {
    if (!value) return null;
    return new Date(`${String(value).replace(' ', 'T')}Z`);
}

export function dateTime(value) {
    const date = typeof value === 'number' ? new Date(value * 1000) : parseUtc(value);
    if (!date) return '–';
    return date.toLocaleString('en', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
}

export function timeAgo(value) {
    const date = typeof value === 'number' ? new Date(value * 1000) : parseUtc(value);
    if (!date) return 'never';
    const s = Math.max(0, (Date.now() - date.getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
}

export function shortClass(name) {
    return name?.split('\\').pop() ?? '';
}

/** "GET /ledgerline/invoices/{invoice}" -> ["GET", "/ledgerline/invoices/{invoice}"] */
export function splitRoute(route) {
    const i = route.indexOf(' ');
    return i < 0 ? ['', route] : [route.slice(0, i), route.slice(i + 1)];
}

/** Why a trace was kept (tail-sampling reason). */
export const REASONS = {
    error: 'Error (5xx / failed)',
    exception: 'Exception',
    slow: 'Slow',
    slow_query: 'Slow query',
    n_plus_one: 'N+1',
    random: 'Random sample',
};
