import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, Download, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import { Button } from '@/components/ui/button';
import { EmptyState, PageHeader } from '@/components/ui/card';
import { api, csrfToken, ensureCsrf } from '@/lib/api';
import { submitZipDownload } from '@/lib/gallery';
import type { AlbumSummary, AssetCard } from '@/lib/types';
import ShareDialog from '@/pages/shares/ShareDialog';

export default function AlbumPage() {
    const { uuid } = useParams();
    const { can } = useAuth();
    const qc = useQueryClient();
    const nav = useNavigate();
    const key = ['album', uuid];
    const q = useQuery({ queryKey: key, queryFn: () => api<{ album: AlbumSummary; assets: AssetCard[] }>(`/albums/${uuid}`) });
    const refresh = () => { qc.invalidateQueries({ queryKey: key }); qc.invalidateQueries({ queryKey: ['albums'] }); };
    const reorder = useMutation({ mutationFn: (uuids: string[]) => api(`/albums/${uuid}/order`, { method: 'PUT', json: { uuids } }), onSettled: refresh });
    const remove = useMutation({ mutationFn: (a: string) => api(`/albums/${uuid}/assets/${a}`, { method: 'DELETE' }), onSettled: refresh });
    const cover = useMutation({ mutationFn: (a: string) => api(`/albums/${uuid}`, { method: 'PATCH', json: { cover_uuid: a } }), onSettled: refresh });
    const del = useMutation({ mutationFn: () => api(`/albums/${uuid}`, { method: 'DELETE' }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['albums'] }); nav('/albums'); } });
    const [selected, setSelected] = useState<string[]>([]);
    const canDownload = can('assets.download_original');
    const download = async (uuids: string[]) => {
        await ensureCsrf();
        const token = csrfToken();
        if (!token || !uuid) return;
        if (uuids.length === 1) {
            const r = await api<{ url: string }>(`/assets/${uuids[0]}/download`, { method: 'POST' });
            window.location.href = r.url;
            return;
        }
        submitZipDownload('/api/downloads', token, uuids.length ? uuids : [], uuids.length ? {} : { album: uuid });
    };
    if (!q.data) return <p className="text-sm text-neutral-500">Loading…</p>;
    const { album, assets } = q.data;
    const manage = can('albums.manage');
    const move = (i: number, d: number) => {
        const ids = assets.map((a) => a.uuid);
        const j = i + d;
        if (j < 0 || j >= ids.length) return;
        [ids[i], ids[j]] = [ids[j], ids[i]];
        qc.setQueryData(key, { album, assets: ids.map((id) => assets.find((a) => a.uuid === id)!) });
        reorder.mutate(ids);
    };
    return (
        <>
            <Link to="/albums" className="mb-3 inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-lia-700"><ArrowLeft className="h-4 w-4" />Albums</Link>
            <PageHeader title={album.title} description={`${album.asset_count} items${album.event ? ` · ${album.event}` : ''}${album.description ? ` · ${album.description}` : ''}`}
                actions={<>
                    {can('shares.manage') ? <ShareDialog target={{ type: 'album', uuid: album.uuid, title: album.title }} /> : null}
                    {canDownload && assets.length ? <Button variant="secondary" onClick={() => void download([])}><Download className="h-4 w-4" />Download album</Button> : null}
                    <Button variant="secondary" asChild><Link to={`/library?album=${album.uuid}`}>Open in library</Link></Button>
                    {manage ? <Button variant="destructive" onClick={() => window.confirm('Delete this album? The media stays in the library.') && del.mutate()}>Delete</Button> : null}
                </>} />
            {selected.length ? (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-lia-200 bg-lia-50/50 p-3 text-sm">
                    <span className="font-medium">{selected.length} selected</span>
                    {canDownload ? <Button size="sm" onClick={() => void download(selected)}><Download className="h-4 w-4" />Download</Button> : null}
                    <Button size="sm" variant="ghost" onClick={() => setSelected(assets.map((a) => a.uuid))}>All</Button>
                    <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
                </div>
            ) : null}
            {!assets.length ? <EmptyState title="This album is empty">Select items in the library and choose “Add to album”.</EmptyState> : null}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                {assets.map((a, i) => (
                    <div key={a.uuid}>
                        <AssetTile asset={a} selected={selected.includes(a.uuid)} onToggle={canDownload ? () => setSelected((s) => (s.includes(a.uuid) ? s.filter((x) => x !== a.uuid) : [...s, a.uuid])) : undefined} />
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
        </>
    );
}
