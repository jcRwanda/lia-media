/** Same-origin fetch wrapper for Laravel Sanctum SPA auth (session cookie + XSRF-TOKEN). */
export class ApiError extends Error {
    constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) {
        super(message);
    }
}

function xsrf(): string | null {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : null;
}

let csrfReady: Promise<void> | null = null;
export function ensureCsrf(): Promise<void> {
    if (!csrfReady) csrfReady = fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);
    return csrfReady;
}

export async function api<T = unknown>(path: string, init: RequestInit & { json?: unknown } = {}): Promise<T> {
    const method = (init.method || 'GET').toUpperCase();
    if (method !== 'GET' && !xsrf()) await ensureCsrf();
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(init.headers as Record<string, string>) };
    const token = xsrf();
    if (token) headers['X-XSRF-TOKEN'] = token;
    let body = init.body;
    if (init.json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(init.json);
    }
    const res = await fetch(`/api${path}`, { ...init, method, headers, body, credentials: 'same-origin' });
    if (res.status === 419 && !(init as { _retried?: boolean })._retried) {
        csrfReady = null;
        await ensureCsrf();
        return api<T>(path, { ...init, _retried: true } as RequestInit);
    }
    const data = res.status === 204 ? null : await res.json().catch(() => null);
    if (!res.ok) throw new ApiError(res.status, data?.message || res.statusText, data?.errors || {});
    return data as T;
}

/** First validation message per field, for forms. */
export function fieldErrors(e: unknown): Record<string, string> {
    if (!(e instanceof ApiError)) return {};
    return Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
}
