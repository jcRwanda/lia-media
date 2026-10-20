/** Staff downloads: originals stream from storage through our own origin, never through the bucket. */

import { csrfToken, ensureCsrf } from '@/lib/api';
import { fetchRemoteFile, saveFilesSequentially, shareFiles, submitZipDownload, usesIndividualDownloads } from '@/lib/gallery';

export type DownloadOutcome = 'zip' | 'shared' | 'saved' | 'cancelled';

export interface SelectionTarget {
    /** Asset uuids to send, in the order they should appear in the archive. */
    uuids: readonly string[];
    /** Endpoint that streams the zip: /api/assets/download, or a whole-album endpoint. */
    zipAction: string;
    /** Name used for an asset when the response does not carry one (phones). */
    titleFor: (uuid: string) => string;
}

/** Submit a hidden form so the browser streams the zip straight to disk instead of buffering it. */
export async function submitZip(zipAction: string, items: readonly string[] = []): Promise<void> {
    await ensureCsrf();
    const token = csrfToken();
    if (!token) {
        throw new Error('Could not start the download. Reload the page and try again.');
    }
    submitZipDownload(zipAction, token, items);
}

/**
 * One selection, two devices: desktop browsers get a single streamed zip, phones/tablets get the
 * files one by one (Web Share API first, sequential saves as the fallback) because mobile OSes are
 * unreliable at unzipping and cannot save a zip into Photos. Detection matches the visitor gallery.
 */
export async function downloadSelection({ uuids, zipAction, titleFor }: SelectionTarget): Promise<DownloadOutcome> {
    if (!uuids.length) {
        return 'cancelled';
    }
    if (usesIndividualDownloads()) {
        const files = await Promise.all(uuids.map((uuid) => fetchRemoteFile(`/api/assets/${uuid}/file`, titleFor(uuid))));
        const outcome = await shareFiles(files);
        if (outcome === 'shared') {
            return 'shared';
        }
        if (outcome === 'cancelled') {
            return 'cancelled';
        }
        saveFilesSequentially(files);

        return 'saved';
    }
    await submitZip(zipAction, uuids);

    return 'zip';
}
