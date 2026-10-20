/** Same-origin fetch wrapper for Laravel Sanctum SPA auth (session cookie + XSRF-TOKEN). */
export class ApiError extends Error {
    constructor(public status: number, message: string, public errors: Record<string, string[]> = {}, public code?: string) {
        super(message);
    }
}

/** `code` the password lock middleware adds so any failing request can be recognised, plus a 423 fallback. */
export const MUST_CHANGE_CODE = 'must_change_password';

export function isPasswordLock(e: unknown): boolean {
    return e instanceof ApiError && (e.code === MUST_CHANGE_CODE || e.status === 423);
}

type PasswordLockListener = () => void;
const passwordLockListeners = new Set<PasswordLockListener>();

/** Notified whenever an API response says the temporary password has not been replaced yet. */
export function onPasswordRequired(listener: PasswordLockListener): () => void {
    passwordLockListeners.add(listener);

    return () => passwordLockListeners.delete(listener);
}

export function csrfToken(): string | null {
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
    if (method !== 'GET' && !csrfToken()) await ensureCsrf();
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(init.headers as Record<string, string>) };
    const token = csrfToken();
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
    if (!res.ok) {
        const error = new ApiError(res.status, data?.message || res.statusText, data?.errors || {}, data?.code);
        if (isPasswordLock(error)) {
            passwordLockListeners.forEach((listener) => listener());
        }
        throw error;
    }
    return data as T;
}

/** First validation message per field, for forms. */
export function fieldErrors(e: unknown): Record<string, string> {
    if (!(e instanceof ApiError)) return {};
    return Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
}
