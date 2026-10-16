import { createSHA256 } from 'hash-wasm';

const CHUNK = 8 * 1024 * 1024;

/** Streaming SHA-256 of a File (constant memory, works for multi-GB videos). */
export async function sha256File(file: Blob, onProgress?: (done: number) => void, signal?: AbortSignal): Promise<string> {
    const h = await createSHA256();
    h.init();
    for (let off = 0; off < file.size; off += CHUNK) {
        if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
        const buf = new Uint8Array(await file.slice(off, off + CHUNK).arrayBuffer());
        h.update(buf);
        onProgress?.(Math.min(file.size, off + CHUNK));
    }
    return h.digest('hex');
}

export async function sha256Base64(blob: Blob): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return btoa(String.fromCharCode(...new Uint8Array(digest)));
}
