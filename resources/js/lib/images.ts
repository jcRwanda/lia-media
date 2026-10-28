export const MAX_IMAGE_EDGE = 2000;
export const JPEG_QUALITY = 0.82;
/** Archive (library/gallery) photos: HEIC is converted at full resolution with near-lossless quality. */
export const ARCHIVE_JPEG_QUALITY = 0.92;

const HEIC_RE = /\.(heic|heif)$/i;

export function isHeic(file: File): boolean {
    return /^image\/hei[cf]/i.test(file.type) || HEIC_RE.test(file.name);
}

/** iPhone files often arrive with an empty MIME type in non-Safari browsers; fill it in from the extension. */
export function withImageType(file: File): File {
    if (file.type) return file;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const type = ({ heic: 'image/heic', heif: 'image/heif', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' } as Record<string, string>)[ext];
    return type ? new File([file], file.name, { type, lastModified: file.lastModified }) : file;
}

/** True for anything that looks like a photo, including HEIC files with no MIME type. */
export function looksLikeImage(file: File): boolean {
    return file.type.startsWith('image/') || /\.(heic|heif|jpe?g|png|webp|gif)$/i.test(file.name);
}

function loadImage(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('decode failed'));
        };
        img.src = url;
    });
}

/** Downscale an image to max 2000px and re-encode as JPEG. Returns the original when the browser can't decode it (e.g. HEIC). */
export async function compressImage(file: File, maxEdge = MAX_IMAGE_EDGE, quality = JPEG_QUALITY): Promise<File> {
    if (!looksLikeImage(file)) return file;
    file = withImageType(file);
    try {
        const img = await loadImage(file);
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        if (!w || !h) return file;
        const scale = Math.min(1, maxEdge / Math.max(w, h));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(w * scale);
        canvas.height = Math.round(h * scale);
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) return file;
        if (scale === 1 && file.type === 'image/jpeg' && blob.size >= file.size) return file;
        const base = file.name.replace(/\.[^.]+$/, '') || 'photo';
        return new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
    } catch {
        return file;
    }
}

export function compressImages(files: File[]): Promise<File[]> {
    return Promise.all(files.map((f) => compressImage(f)));
}

/**
 * For archive uploads that keep the original: only HEIC/HEIF is converted (full resolution,
 * quality 0.92) so every browser can preview it. Everything else is returned untouched.
 * If the browser cannot decode HEIC the original is kept and uploaded as-is.
 */
export async function convertHeicForArchive(file: File): Promise<File> {
    if (!isHeic(file)) return file;
    const typed = withImageType(file);
    const out = await compressImage(typed, Number.POSITIVE_INFINITY, ARCHIVE_JPEG_QUALITY);
    if (out === typed) return typed;
    return new File([out], out.name, { type: 'image/jpeg', lastModified: file.lastModified });
}
