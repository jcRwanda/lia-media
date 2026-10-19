import { afterEach, describe, expect, it, vi } from 'vitest';
import { clampIndex, fetchSelectedFiles, filenameFromDisposition, saveFilesSequentially, SHARE_CHUNK_SIZE, shareFiles, stepIndex, submitZipDownload, swipeDirection, toggleSelection, usesIndividualDownloads } from './gallery';

describe('toggleSelection', () => {
    it('adds an unselected item and removes a selected one', () => {
        expect(toggleSelection(['a'], 'b')).toEqual(['a', 'b']);
        expect(toggleSelection(['a', 'b'], 'a')).toEqual(['b']);
    });
    it('does not mutate the previous selection', () => {
        const prev = ['a'];
        toggleSelection(prev, 'b');
        expect(prev).toEqual(['a']);
    });
});

describe('stepIndex', () => {
    it('clamps navigation to the gallery bounds', () => {
        expect(stepIndex(0, -1, 3)).toBe(0);
        expect(stepIndex(2, 1, 3)).toBe(2);
        expect(stepIndex(1, 1, 3)).toBe(2);
        expect(clampIndex(-5, 0)).toBe(0);
    });
});

describe('swipeDirection', () => {
    it('moves on clear horizontal swipes only', () => {
        expect(swipeDirection(-80, 10)).toBe('next');
        expect(swipeDirection(80, -10)).toBe('prev');
    });
    it('ignores taps, short drags and vertical scrolling', () => {
        expect(swipeDirection(5, 0)).toBeNull();
        expect(swipeDirection(-80, -90)).toBeNull();
        expect(swipeDirection(10, -80)).toBeNull();
    });
});

describe('usesIndividualDownloads', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('is true only for coarse pointers with no hover', () => {
        vi.stubGlobal('matchMedia', vi.fn((query: string) => ({ matches: query === '(hover: none) and (pointer: coarse)' })));
        expect(usesIndividualDownloads()).toBe(true);
    });

    it('is false for a desktop-class pointer', () => {
        vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
        expect(usesIndividualDownloads()).toBe(false);
    });
});

describe('filenameFromDisposition', () => {
    it('reads quoted, unquoted and UTF-8 file names', () => {
        expect(filenameFromDisposition('attachment; filename="photo.webp"')).toBe('photo.webp');
        expect(filenameFromDisposition('attachment; filename=photo.jpg')).toBe('photo.jpg');
        expect(filenameFromDisposition("attachment; filename*=UTF-8''caf%C3%A9.jpg")).toBe('café.jpg');
        expect(filenameFromDisposition(null)).toBeNull();
        expect(filenameFromDisposition('attachment')).toBeNull();
    });
});

describe('fetchSelectedFiles', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('fetches every item from our origin and names it from the response', async () => {
        const fetchMock = vi.fn(async (url: string) => new Response('bytes:' + url, {
            status: 200,
            headers: { 'Content-Disposition': 'attachment; filename="from-server.webp"', 'Content-Type': 'image/webp' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const files = await fetchSelectedFiles('/api/s/tok', ['u1', 'u2'], (uuid) => `${uuid}.jpg`);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenCalledWith('/api/s/tok/items/u1/file', { credentials: 'same-origin', headers: { Accept: 'application/octet-stream' } });
        expect(files.map((f) => f.name)).toEqual(['from-server.webp', 'from-server.webp']);
        expect(files[0].type).toBe('image/webp');
        expect(await files[0].text()).toBe('bytes:/api/s/tok/items/u1/file');
    });

    it('fails loudly when one file cannot be prepared', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('no', { status: 404 })));

        await expect(fetchSelectedFiles('/api/s/tok', ['u1'], () => 'x')).rejects.toThrow('Could not prepare');
    });
});

describe('shareFiles', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const files = (n: number) => Array.from({ length: n }, (_, i) => new File(['x'], `f${i}.jpg`, { type: 'image/jpeg' }));

    it('shares in chunks small enough for mobile share sheets', async () => {
        const share = vi.fn(async (_data: ShareData) => {});
        const canShare = vi.fn(() => true);
        vi.stubGlobal('navigator', { share, canShare });

        const result = await shareFiles(files(SHARE_CHUNK_SIZE + 3));

        expect(result).toBe('shared');
        expect(share).toHaveBeenCalledTimes(2);
        expect((share.mock.calls[0][0] as ShareData).files).toHaveLength(SHARE_CHUNK_SIZE);
        expect((share.mock.calls[1][0] as ShareData).files).toHaveLength(3);
    });

    it('reports cancellation and falls back when unsupported', async () => {
        const abort = new DOMException('cancelled', 'AbortError');
        vi.stubGlobal('navigator', { canShare: () => true, share: vi.fn(async () => Promise.reject(abort)) });
        expect(await shareFiles(files(1))).toBe('cancelled');

        vi.stubGlobal('navigator', { canShare: () => false, share: vi.fn() });
        expect(await shareFiles(files(1))).toBe('unsupported');

        vi.stubGlobal('navigator', {});
        expect(await shareFiles(files(1))).toBe('unsupported');
    });
});

describe('saveFilesSequentially', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('saves each file on its own with the original name', () => {
        vi.useFakeTimers();
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        const createObjectURL = vi.fn(() => 'blob:x');
        vi.stubGlobal('URL', { createObjectURL, revokeObjectURL: vi.fn() });

        saveFilesSequentially([new File(['a'], 'one.jpg'), new File(['b'], 'two.jpg')], 100);
        expect(click).not.toHaveBeenCalled();

        vi.advanceTimersByTime(50);
        expect(click).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(50);

        expect(click).toHaveBeenCalledTimes(2);
        expect(createObjectURL).toHaveBeenCalledTimes(2);
        expect(document.querySelectorAll('a')).toHaveLength(0);
        vi.unstubAllGlobals();
    });
});

describe('submitZipDownload', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('posts the csrf token and every selected item, then submits', () => {
        const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});

        submitZipDownload('/api/s/tok123/download', 'csrf-abc', ['u1', 'u2']);

        const form = document.querySelector('form') as HTMLFormElement;
        expect(form.method).toBe('post');
        expect(form.action).toContain('/api/s/tok123/download');
        expect([...form.querySelectorAll('input')].map((i) => [i.name, i.value])).toEqual([
            ['_token', 'csrf-abc'],
            ['items[]', 'u1'],
            ['items[]', 'u2'],
        ]);
        expect(submit).toHaveBeenCalledOnce();
    });
});
