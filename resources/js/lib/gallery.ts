/** Visitor gallery helpers: multi-select, swipe navigation, and streaming/individual downloads. */

/** Mirrors PublicShareController::MAX_ZIP_ITEMS — the server rejects larger batches. */
export const MAX_ZIP_ITEMS = 500;

/** Horizontal travel (px) a gesture must beat before it counts as a swipe. */
export const SWIPE_THRESHOLD = 40;

/** Mobile share sheets only accept a limited number of files per call; iOS is the tightest. */
export const SHARE_CHUNK_SIZE = 10;

export function toggleSelection(selected: readonly string[], uuid: string): string[] {
    return selected.includes(uuid) ? selected.filter((u) => u !== uuid) : [...selected, uuid];
}

export function clampIndex(index: number, length: number): number {
    return Math.min(Math.max(index, 0), Math.max(length - 1, 0));
}

export function stepIndex(current: number, delta: number, length: number): number {
    return clampIndex(current + delta, length);
}

/**
 * Horizontal swipes move between items; anything more vertical than horizontal is left to the
 * browser (scrolling), so taps and drags on phones don't hijack the page.
 */
export function swipeDirection(dx: number, dy: number): 'next' | 'prev' | null {
    if (Math.abs(dx) < SWIPE_THRESHOLD || Math.abs(dx) <= Math.abs(dy)) return null;

    return dx < 0 ? 'next' : 'prev';
}

/**
 * A zip is convenient on a desktop browser but painful on a phone: mobile OSes are unreliable at
 * unzipping downloads and can't save a zip into Photos. Phones/tables get files shared individually.
 * Touch devices with no hover are the signal we use (mouse-free laptops still report fine pointer).
 */
export function usesIndividualDownloads(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }

    return window.matchMedia('(hover: none) and (pointer: coarse)').matches;
}

export function filenameFromDisposition(header: string | null): string | null {
    if (!header) {
        return null;
    }
    const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (utf8) {
        try {
            return decodeURIComponent(utf8[1]);
        } catch {
            return utf8[1];
        }
    }
    const plain = /filename="?([^";]+)"?/i.exec(header);

    return plain ? plain[1] : null;
}

/**
 * Fetch the real bytes of each selected item from our own origin (the file endpoint), so they can be
 * handed to the share sheet or saved. Requests run in parallel to keep the share gesture valid.
 */
export async function fetchSelectedFiles(basePath: string, uuids: readonly string[], titleFor: (uuid: string) => string): Promise<File[]> {
    return Promise.all(uuids.map(async (uuid) => {
        const res = await fetch(`${basePath}/items/${uuid}/file`, { credentials: 'same-origin', headers: { Accept: 'application/octet-stream' } });
        if (!res.ok) {
            throw new Error('Could not prepare one of the files.');
        }
        const blob = await res.blob();
        const bytes = await blob.arrayBuffer();

        return new File([bytes], filenameFromDisposition(res.headers.get('Content-Disposition')) ?? titleFor(uuid), { type: blob.type || 'application/octet-stream' });
    }));
}

type ShareNavigator = Navigator & { canShare?: (data: ShareData) => boolean; share?: (data: ShareData) => Promise<void> };

/**
 * Hand the files to the OS share sheet (save to Photos/Files, AirDrop, mail…), chunked to stay
 * within each browser's file-count cap. Returns whether they were shared, the user cancelled, or
 * the API is unavailable and callers should fall back to saving each file.
 */
export async function shareFiles(files: File[]): Promise<'shared' | 'cancelled' | 'unsupported'> {
    const nav = navigator as ShareNavigator;
    if (!files.length || typeof nav.share !== 'function' || typeof nav.canShare !== 'function') {
        return 'unsupported';
    }
    const chunks: File[][] = [];
    for (let i = 0; i < files.length; i += SHARE_CHUNK_SIZE) {
        chunks.push(files.slice(i, i + SHARE_CHUNK_SIZE));
    }
    const canShare = nav.canShare.bind(nav);
    if (!chunks.every((chunk) => canShare({ files: chunk }))) {
        return 'unsupported';
    }
    try {
        for (const chunk of chunks) {
            await nav.share({ files: chunk });
        }

        return 'shared';
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') {
            return 'cancelled';
        }

        return 'unsupported';
    }
}

/** Last resort when there's no share sheet: save each file, spaced out so mobile browsers keep all. */
export function saveFilesSequentially(files: File[], delayMs = 600): void {
    files.forEach((file, i) => {
        window.setTimeout(() => {
            const url = URL.createObjectURL(file);
            const link = document.createElement('a');
            link.href = url;
            link.download = file.name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
        }, i * delayMs);
    });
}

/**
 * Submit a hidden form so the browser streams the zip straight to disk — fetching it into a blob
 * would hold the whole archive in memory, which phones choke on.
 */
export function submitZipDownload(action: string, csrfToken: string, items: readonly string[]): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = action;
    form.hidden = true;
    const add = (name: string, value: string) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };
    add('_token', csrfToken);
    items.forEach((uuid) => add('items[]', uuid));
    document.body.appendChild(form);
    form.submit();
    setTimeout(() => form.remove(), 0);
}
