import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as gallery from './gallery';
import { downloadSelection, submitZip } from './downloads';

vi.mock('@/lib/api', () => ({
    ensureCsrf: vi.fn(async () => {}),
    csrfToken: vi.fn(() => 'csrf-abc'),
}));

let individual = false;

vi.mock('@/lib/gallery', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./gallery')>();

    return { ...actual, usesIndividualDownloads: () => individual, submitZipDownload: vi.fn(), fetchRemoteFile: vi.fn(), shareFiles: vi.fn(), saveFilesSequentially: vi.fn() };
});

const target = { uuids: ['u1', 'u2'], zipAction: '/api/assets/download', titleFor: (uuid: string) => `${uuid}.jpg` };
const zip = gallery.submitZipDownload as unknown as ReturnType<typeof vi.fn>;
const fetchFile = gallery.fetchRemoteFile as unknown as ReturnType<typeof vi.fn>;
const share = gallery.shareFiles as unknown as ReturnType<typeof vi.fn>;
const save = gallery.saveFilesSequentially as unknown as ReturnType<typeof vi.fn>;

beforeEach(() => {
    individual = false;
    vi.clearAllMocks();
});

describe('submitZip', () => {
    it('streams a zip through a hidden form post', async () => {
        await submitZip('/api/assets/download', ['u1']);

        expect(zip).toHaveBeenCalledWith('/api/assets/download', 'csrf-abc', ['u1']);
    });

    it('asks the user to reload rather than posting without a csrf token', async () => {
        const { csrfToken } = await import('@/lib/api');
        vi.mocked(csrfToken).mockReturnValueOnce(null);

        await expect(submitZip('/api/assets/download')).rejects.toThrow('Reload the page');
        expect(zip).not.toHaveBeenCalled();
    });
});

describe('downloadSelection', () => {
    it('is a no-op when nothing is selected', async () => {
        expect(await downloadSelection({ ...target, uuids: [] })).toBe('cancelled');
        expect(zip).not.toHaveBeenCalled();
        expect(fetchFile).not.toHaveBeenCalled();
    });

    it('sends one zip request from the desktop, with every uuid', async () => {
        expect(await downloadSelection(target)).toBe('zip');

        expect(zip).toHaveBeenCalledWith('/api/assets/download', 'csrf-abc', ['u1', 'u2']);
        expect(fetchFile).not.toHaveBeenCalled();
    });

    it('shares the files one by one on phones', async () => {
        individual = true;
        fetchFile.mockResolvedValue(new File(['a'], 'u1.jpg'));
        share.mockResolvedValue('shared');

        expect(await downloadSelection(target)).toBe('shared');

        expect(fetchFile).toHaveBeenCalledTimes(2);
        expect(fetchFile).toHaveBeenCalledWith('/api/assets/u1/file', 'u1.jpg');
        expect(share).toHaveBeenCalledOnce();
        expect(zip).not.toHaveBeenCalled();
    });

    it('saves each file itself when the phone has no share sheet', async () => {
        individual = true;
        fetchFile.mockResolvedValue(new File(['a'], 'u1.jpg'));
        share.mockResolvedValue('unsupported');

        expect(await downloadSelection(target)).toBe('saved');

        expect(save).toHaveBeenCalledOnce();
        expect(zip).not.toHaveBeenCalled();
    });

    it('stops when the user cancels the share sheet, without saving twice', async () => {
        individual = true;
        fetchFile.mockResolvedValue(new File(['a'], 'u1.jpg'));
        share.mockResolvedValue('cancelled');

        expect(await downloadSelection(target)).toBe('cancelled');

        expect(save).not.toHaveBeenCalled();
        expect(zip).not.toHaveBeenCalled();
    });

    it('surfaces preparation failures so the page can show them', async () => {
        individual = true;
        fetchFile.mockRejectedValue(new Error('Could not prepare one of the files.'));

        await expect(downloadSelection(target)).rejects.toThrow('Could not prepare');
        expect(share).not.toHaveBeenCalled();
    });
});
