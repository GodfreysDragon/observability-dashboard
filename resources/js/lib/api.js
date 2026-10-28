let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
let onUnauthorized = () => {};

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || `Request failed (${status})`);
        this.status = status;
        this.errors = body?.errors ?? {};
    }
}

export function setCsrfToken(token) {
    if (token) csrfToken = token;
}

export function handleUnauthorized(callback) {
    onUnauthorized = callback;
}

/**
 * Serialise params the way Laravel reads them: arrays become key[]=a&key[]=b, empty values are dropped.
 */
export function toQuery(params = {}) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') continue;
        if (Array.isArray(value)) value.forEach((item) => query.append(`${key}[]`, item));
        else query.append(key, value);
    }
    const string = query.toString();
    return string ? `?${string}` : '';
}

export async function request(method, url, { params, body, signal } = {}) {
    const response = await fetch(`/api/admin${url}${toQuery(params)}`, {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (response.status === 204) return null;

    const data = await response.json().catch(() => null);

    if (response.status === 401) onUnauthorized();
    if (!response.ok) throw new ApiError(response.status, data);

    return data;
}

export const api = {
    get: (url, params, options = {}) => request('GET', url, { ...options, params }),
    post: (url, body) => request('POST', url, { body }),
    put: (url, body) => request('PUT', url, { body }),
    delete: (url) => request('DELETE', url),
};
