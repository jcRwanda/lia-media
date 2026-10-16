import { api } from '@/lib/api';
import { sha256File } from '@/lib/hash';
import type { PresignedPut, UploadSessionDto } from '@/lib/types';

export interface UploadContext {
    title?: string;
    project_id?: number | null;
    cohort_id?: number | null;
    event_id?: number | null;
    category_id?: number | null;
    album_id?: number | null;
    credit?: string | null;
    tags?: string[];
    width?: number;
    height?: number;
    duration_ms?: number;
}

export type Phase = 'queued' | 'hashing' | 'uploading' | 'finalizing' | 'derivatives' | 'done' | 'duplicate' | 'error' | 'paused';

export interface Progress {
    phase: Phase;
    loaded: number;
    total: number;
    message?: string;
    assetUuid?: string;
}

type OnProgress = (p: Progress) => void;

const PARALLEL = 4;
const resumeKey = (f: File) => `lia-upload:${f.name}:${f.size}:${f.lastModified}`;

/** PUT a blob to a presigned URL with progress. Resolves with the ETag header. */
export function putBlob(url: string, blob: Blob, headers: Record<string, string>, onBytes: (n: number) => void, signal?: AbortSignal): Promise<string> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
        xhr.upload.onprogress = (e) => onBytes(e.loaded);
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve((xhr.getResponseHeader('ETag') || '').replaceAll('"', '')) : reject(new Error(`Storage refused the upload (HTTP ${xhr.status}).`)));
        xhr.onerror = () => reject(new Error('Network error while uploading. You can resume.'));
        xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));
        signal?.addEventListener('abort', () => xhr.abort());
        xhr.send(blob);
    });
}

async function withRetry<T>(fn: () => Promise<T>, tries = 3): Promise<T> {
    let last: unknown;
    for (let i = 0; i < tries; i++) {
        try {
            return await fn();
        } catch (e) {
            if ((e as Error).name === 'AbortError') throw e;
            last = e;
            await new Promise((r) => setTimeout(r, 800 * 2 ** i));
        }
    }
    throw last;
}

/**
 * Hash → initiate (dedupe) → direct PUT or multipart parts to R2 → finalize.
 * Interrupted multipart uploads resume from the parts storage already holds.
 */
export async function uploadFile(file: File, ctx: UploadContext, onProgress: OnProgress, signal?: AbortSignal): Promise<{ assetUuid: string; duplicate: boolean }> {
    const total = file.size;
    let session: UploadSessionDto | null = null;
    let put: PresignedPut | null = null;
    let done: number[] = [];

    const saved = localStorage.getItem(resumeKey(file));
    if (saved) {
        try {
            const r = await api<{ upload: UploadSessionDto; parts: { part_number: number }[] }>(`/uploads/${saved}`);
            if (r.upload.status === 'uploading') {
                session = r.upload;
                done = r.parts.map((p) => p.part_number);
                if (session.mode === 'single') put = (await api<{ put: PresignedPut }>(`/uploads/${saved}/renew`, { method: 'POST' })).put;
            }
        } catch {
            /* stale entry */
        }
        if (!session) localStorage.removeItem(resumeKey(file));
    }

    if (!session) {
        onProgress({ phase: 'hashing', loaded: 0, total });
        const sha256 = await sha256File(file, (n) => onProgress({ phase: 'hashing', loaded: n, total }), signal);
        const init = await api<{ status: string; upload?: UploadSessionDto; put?: PresignedPut; asset?: { uuid: string } }>('/uploads', {
            method: 'POST',
            json: { filename: file.name, mime_type: file.type || 'application/octet-stream', size_bytes: file.size, sha256, context: ctx },
        });
        if (init.status === 'duplicate') {
            onProgress({ phase: 'duplicate', loaded: total, total, assetUuid: init.asset!.uuid, message: 'Already in the archive' });
            return { assetUuid: init.asset!.uuid, duplicate: true };
        }
        session = init.upload!;
        put = init.put ?? null;
        localStorage.setItem(resumeKey(file), session.uuid);
    }

    const s = session;
    if (s.mode === 'single') {
        await withRetry(() => putBlob(put!.url, file, put!.headers, (n) => onProgress({ phase: 'uploading', loaded: n, total }), signal));
    } else {
        const partSize = s.part_size!;
        const sizes: Record<number, number> = {};
        const sent = () => Object.values(sizes).reduce((a, b) => a + b, 0);
        done.forEach((n) => (sizes[n] = Math.min(partSize, total - (n - 1) * partSize)));
        const todo = Array.from({ length: s.part_count }, (_, i) => i + 1).filter((n) => !done.includes(n));
        onProgress({ phase: 'uploading', loaded: sent(), total });
        let cursor = 0;
        const worker = async () => {
            while (cursor < todo.length) {
                const batch = todo.slice(cursor, cursor + 1);
                cursor += 1;
                const n = batch[0];
                const blob = file.slice((n - 1) * partSize, Math.min(total, n * partSize));
                await withRetry(async () => {
                    const { urls } = await api<{ urls: Record<number, string> }>(`/uploads/${s.uuid}/parts`, { method: 'POST', json: { part_numbers: [n] } });
                    await putBlob(urls[n], blob, {}, (b) => { sizes[n] = b; onProgress({ phase: 'uploading', loaded: sent(), total }); }, signal);
                    sizes[n] = blob.size;
                });
            }
        };
        await Promise.all(Array.from({ length: Math.min(PARALLEL, todo.length) }, worker));
    }

    onProgress({ phase: 'finalizing', loaded: total, total });
    const res = await api<{ status: string; asset: { uuid: string } }>(`/uploads/${s.uuid}/complete`, { method: 'POST' });
    localStorage.removeItem(resumeKey(file));
    return { assetUuid: res.asset.uuid, duplicate: res.status === 'duplicate' };
}
