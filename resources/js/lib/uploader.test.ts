import { vi } from 'vitest';
import { uploadFile } from './uploader';

vi.mock('./hash', () => ({ sha256File: vi.fn(async () => 'a'.repeat(64)) }));
const apiMock = vi.fn();
vi.mock('./api', () => ({ api: (...a: unknown[]) => apiMock(...a) }));

class FakeXHR {
    static sent: { url: string; size: number }[] = [];
    url = ''; status = 200; upload: { onprogress?: (e: { loaded: number }) => void } = {};
    onload?: () => void; onerror?: () => void; onabort?: () => void;
    open(_m: string, url: string) { this.url = url; }
    setRequestHeader() {}
    getResponseHeader() { return '"etag"'; }
    send(b: Blob) { FakeXHR.sent.push({ url: this.url, size: b.size }); this.upload.onprogress?.({ loaded: b.size }); setTimeout(() => this.onload?.(), 0); }
    abort() {}
}

beforeEach(() => { apiMock.mockReset(); FakeXHR.sent = []; localStorage.clear(); vi.stubGlobal('XMLHttpRequest', FakeXHR); });

test('duplicate short-circuits without uploading bytes', async () => {
    apiMock.mockResolvedValueOnce({ status: 'duplicate', asset: { uuid: 'A1' } });
    const r = await uploadFile(new File(['hello'], 'a.jpg', { type: 'image/jpeg' }), {}, () => undefined);
    expect(r).toEqual({ assetUuid: 'A1', duplicate: true });
    expect(FakeXHR.sent).toHaveLength(0);
});

test('multipart resumes only the missing parts', async () => {
    const file = new File([new Uint8Array(25)], 'v.mp4', { type: 'video/mp4' });
    localStorage.setItem(`lia-upload:v.mp4:25:${file.lastModified}`, 'U1');
    apiMock.mockImplementation(async (path: string, init?: { json?: { part_numbers: number[] } }) => {
        if (path === '/uploads/U1') return { upload: { uuid: 'U1', mode: 'multipart', part_size: 10, part_count: 3, status: 'uploading' }, parts: [{ part_number: 1 }, { part_number: 3 }] };
        if (path === '/uploads/U1/parts') return { urls: Object.fromEntries(init!.json!.part_numbers.map((n) => [n, `https://r2/part${n}`])) };
        if (path === '/uploads/U1/complete') return { status: 'completed', asset: { uuid: 'A2' } };
        throw new Error(path);
    });
    const r = await uploadFile(file, {}, () => undefined);
    expect(r.assetUuid).toBe('A2');
    expect(FakeXHR.sent).toEqual([{ url: 'https://r2/part2', size: 10 }]);
    expect(localStorage.length).toBe(0);
});
