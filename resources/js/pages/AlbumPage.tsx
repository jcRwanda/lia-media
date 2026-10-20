import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, Download, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import SelectionBar from '@/components/media/SelectionBar';
import { Button } from '@/components/ui/button';
import { EmptyState, PageHeader } from '@/components/ui/card';
import { api } from '@/lib/api';
import { downloadSelection, submitZip } from '@/lib/downloads';
import { usesIndividualDownloads } from '@/lib/gallery';
import type { AlbumSummary, AssetCard } from '@/lib/types';
import ShareDialog from '@/pages/shares/ShareDialog';

export default function AlbumPage() {
    const { uuid } = useParams();
    const { can } = useAuth();
    const qc = useQueryClient();
    const nav = useNavigate();
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const key = ['album', uuid];
    const q = useQuery({ queryKey: key, queryFn: () => api<{ album: AlbumSummary; assets: AssetCard[] }>(`/albums/${uuid}`) });
    const refresh = () => { qc.invalidateQueries({ queryKey: key }); qc.invalidateQueries({ queryKey: ['albums'] }); };
    const reorder = useMutation({ mutationFn: (uuids: string[]) => api(`/albums/${uuid}/order`, { method: 'PUT', json: { uuids } }), onSettled: refresh });
    const remove = useMutation({ mutationFn: (a: string) => api(`/albums/${uuid}/assets/${a}`, { method: 'DELETE' }), onSettled: refresh });
    const cover = useMutation({ mutationFn: (a: string) => api(`/albums/${uuid}`, { method: 'PATCH', json: { cover_uuid: a } }), onSettled: refresh });
    const del = useMutation({ mutationFn: () => api(`/albums/${uuid}`, { method: 'DELETE' }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['albums'] }); nav('/albums'); } });
    if (!q.data) return <p className="text-sm text-neutral-500">Loading…</p>;
    const { album, assets } = q.data;
    const manage = can('albums.manage');
    const canEdit = can('assets.edit');
    const canDownload = can('assets.download_original');
    const selectable = canEdit || canDownload;
    const zipAction = `/api/albums/${album.uuid}/download`;
    const move = (i: number, d: number) => {
        const ids = assets.map((a) => a.uuid);
        const j = i + d;
        if (j < 0 || j >= ids.length) return;
        [ids[i], ids[j]] = [ids[j], ids[i]];
        qc.setQueryData(key, { album, assets: ids.map((id) => assets.find((a) => a.uuid === id)!) });
        reorder.mutate(ids);
    };
    /** The whole album as one archive; phones get the files shared individually instead. */
    const downloadAlbum = async () => {
        if (busy) return;
        setBusy(true);
        setNotice(null);
        try {
            if (usesIndividualDownloads()) {
                const outcome = await downloadSelection({ uuids: assets.map((a) => a.uuid), zipAction, titleFor: () => album.title });
                if (outcome === 'shared') { setSelected([]); setSelecting(false); }
            } else {
                await submitZip(zipAction);
            }
        } catch (err) {
            setNotice((err as Error).message || 'Could not prepare the download.');
        } finally {
            setBusy(false);
        }
    };
    const downloadSelected = async () => {
        if (!selected.length || busy) return;
        setBusy(true);
        setNotice(null);
        try {
            const outcome = await downloadSelection({
                uuids: selected,
                zipAction,
                titleFor: (id) => assets.find((a) => a.uuid === id)?.title ?? album.title,
            });
            if (outcome === 'shared') { setSelected([]); setSelecting(false); }
        } catch (err) {
            setNotice((err as Error).message || 'Could not prepare the download.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <Link to="/albums" className="mb-3 inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-lia-700"><ArrowLeft className="h-4 w-4" />Albums</Link>
            <PageHeader title={album.title} description={`${album.asset_count} items${album.event ? ` · ${album.event}` : ''}${album.description ? ` · ${album.description}` : ''}`}
                actions={<>
                    {canDownload ? <Button variant="secondary" disabled={busy || !assets.length} onClick={downloadAlbum}><Download className="h-4 w-4" />{busy ? 'Preparing…' : 'Download album'}</Button> : null}
                    {can('shares.manage') ? <ShareDialog target={{ type: 'album', uuid: album.uuid, title: album.title }} /> : null}
                    <Button variant="secondary" asChild><Link to={`/library?album=${album.uuid}`}>Open in library</Link></Button>
                    {selectable ? <Button variant={selecting ? 'default' : 'secondary'} onClick={() => { setSelecting(!selecting); setSelected([]); setNotice(null); }}>{selecting ? 'Done' : 'Select'}</Button> : null}
                    {manage ? <Button variant="destructive" onClick={() => window.confirm('Delete this album? The media stays in the library.') && del.mutate()}>Delete</Button> : null}
                </>} />
            {notice && !selecting ? <p role="alert" className="mb-3 rounded-lg bg-lia-50 p-2 text-sm text-lia-700">{notice}</p> : null}
            {!assets.length ? <EmptyState title="This album is empty">Select items in the library and choose “Add to album”.</EmptyState> : null}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                {assets.map((a, i) => (
                    <div key={a.uuid}>
                        <AssetTile asset={a} selected={selected.includes(a.uuid)} onToggle={selecting && (canEdit || canDownload) ? () => { setNotice(null); setSelected((s) => (s.includes(a.uuid) ? s.filter((x) => x !== a.uuid) : [...s, a.uuid])); } : undefined} />
                        {manage ? (
                            <div className="mt-1 flex justify-between">
                                <div className="flex">
                                    <Button size="icon" variant="ghost" aria-label="Move earlier" onClick={() => move(i, -1)}><ArrowUp className="h-4 w-4" /></Button>
                                    <Button size="icon" variant="ghost" aria-label="Move later" onClick={() => move(i, 1)}><ArrowDown className="h-4 w-4" /></Button>
                                </div>
                                <div className="flex">
                                    <Button size="icon" variant="ghost" aria-label="Use as cover" className={album.cover_uuid === a.uuid ? 'text-lia-600' : ''} onClick={() => cover.mutate(a.uuid)}><Star className="h-4 w-4" /></Button>
                                    <Button size="icon" variant="ghost" aria-label="Remove from album" onClick={() => remove.mutate(a.uuid)}><Trash2 className="h-4 w-4" /></Button>
                                </div>
                            </div>
                        ) : null}
                    </div>
                ))}
            </div>
            {selecting ? <div className="h-24" /> : null}
            {selecting ? (
                <SelectionBar
                    count={selected.length}
                    canDownload={canDownload}
                    busy={busy}
                    notice={notice}
                    onSelectAll={() => setSelected(assets.map((a) => a.uuid))}
                    onClear={() => { setSelected([]); setNotice(null); }}
                    onCancel={() => { setSelecting(false); setSelected([]); setNotice(null); }}
                    onDownload={downloadSelected}
                />
            ) : null}
        </>
    );
}
