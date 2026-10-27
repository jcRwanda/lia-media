export const MAX_IMAGE_EDGE = 2000;
export const JPEG_QUALITY = 0.82;

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
    if (!file.type.startsWith('image/') && !/\.(heic|heif|jpe?g|png|webp)$/i.test(file.name)) return file;
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
