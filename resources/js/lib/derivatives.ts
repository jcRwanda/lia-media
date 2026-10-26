import { api } from '@/lib/api';
import { putBlob } from '@/lib/uploader';
import { sha256Base64 } from '@/lib/hash';

export interface Rendition { type: 'thumb' | 'preview' | 'poster'; blob: Blob; width: number; height: number }

const SIZES = { thumb: 480, preview: 1920, poster: 1920 } as const;

function fit(w: number, h: number, max: number) {
    const s = Math.min(1, max / Math.max(w, h));
    return { width: Math.max(1, Math.round(w * s)), height: Math.max(1, Math.round(h * s)) };
}

async function toBlob(source: CanvasImageSource, sw: number, sh: number, max: number): Promise<{ blob: Blob; width: number; height: number }> {
    const { width, height } = fit(sw, sh, max);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d')!;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, 0, 0, width, height);
    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, 'image/webp', 0.82));
    const out = blob && blob.type === 'image/webp' ? blob : await new Promise<Blob | null>((r) => canvas.toBlob(r, 'image/jpeg', 0.85));
    if (!out) throw new Error('Could not encode preview');
    return { blob: out, width, height };
}

/** Image: EXIF orientation applied by createImageBitmap; metadata (incl. GPS) is not copied into derivatives. */
export async function imageRenditions(file: File): Promise<{ renditions: Rendition[]; width: number; height: number }> {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' } as ImageBitmapOptions);
    const renditions: Rendition[] = [];
    for (const type of ['thumb', 'preview'] as const) renditions.push({ type, ...(await toBlob(bmp, bmp.width, bmp.height, SIZES[type])) });
    const dims = { width: bmp.width, height: bmp.height };
    bmp.close();
    return { renditions, ...dims };
}

function sanitizedDimension(value: number | undefined): number | undefined {
    if (value === undefined) return undefined;
    return Number.isFinite(value) && value > 0 ? value : undefined;
}

/** Video: grab a frame ~1s in (or 10%) for poster + thumb. */
export async function videoRenditions(file: File): Promise<{ renditions: Rendition[]; width: number; height: number; duration_ms: number }> {
    const url = URL.createObjectURL(file);
    try {
        const v = document.createElement('video');
        v.muted = true;
        v.preload = 'metadata';
        v.src = url;
        await new Promise<void>((res, rej) => { v.onloadedmetadata = () => res(); v.onerror = () => rej(new Error('This browser cannot read the video for a poster frame.')); });
        const width = sanitizedDimension(v.videoWidth);
        const height = sanitizedDimension(v.videoHeight);
        if (!width || !height) {
            throw new Error('This video does not expose valid dimensions.');
        }
        v.currentTime = Math.min(1, (v.duration || 1) * 0.1);
        await new Promise<void>((res) => { v.onseeked = () => res(); });
        const renditions: Rendition[] = [];
        for (const type of ['poster', 'thumb'] as const) renditions.push({ type, ...(await toBlob(v, width, height, SIZES[type])) });
        const durationMs = Number.isFinite(v.duration) && v.duration > 0 ? Math.round(v.duration * 1000) : 0;
        return { renditions, width, height, duration_ms: durationMs };
    } finally {
        URL.revokeObjectURL(url);
    }
}

export async function uploadRenditions(assetUuid: string, renditions: Rendition[]): Promise<void> {
    for (const r of renditions) {
        const sha = await sha256Base64(r.blob);
        const { put } = await api<{ put: { url: string; headers: Record<string, string> } }>(`/assets/${assetUuid}/derivatives`, {
            method: 'POST', json: { type: r.type, mime_type: r.blob.type, width: r.width, height: r.height, size_bytes: r.blob.size, sha256_base64: sha },
        });
        await putBlob(put.url, r.blob, put.headers, () => undefined);
        await api(`/assets/${assetUuid}/derivatives/${r.type}/complete`, { method: 'POST' });
    }
}

export async function renditionsFor(file: File) {
    if (file.type.startsWith('image/')) return imageRenditions(file).catch(() => null);
    if (file.type.startsWith('video/')) return videoRenditions(file).catch(() => null);
    return null;
}
