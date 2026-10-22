import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import { Button } from '@/components/ui/button';
import { Card, EmptyState, PageHeader } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { api, csrfToken, ensureCsrf } from '@/lib/api';
import { submitZipDownload } from '@/lib/gallery';
import type { AssetCard, Paginated } from '@/lib/types';
import { cn } from '@/lib/utils';

const FILTERS = ['q', 'kind', 'review_state', 'project_id', 'event_id', 'cohort_id', 'category_id', 'album', 'tag', 'date_from', 'date_to', 'sort'] as const;

export default function LibraryPage() {
    const [params, setParams] = useSearchParams();
    const [showFilters, setShowFilters] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [bulkAlbum, setBulkAlbum] = useState('');
    const [bulkTags, setBulkTags] = useState('');
    const { can } = useAuth();
    const lk = useLookups().data;
    const qc = useQueryClient();
    const page = Number(params.get('page') || 1);
    const qs = new URLSearchParams(Object.fromEntries(FILTERS.flatMap((f) => (params.get(f) ? [[f, params.get(f)!]] : [])))).toString();
    const q = useQuery({ queryKey: ['assets', qs, page], queryFn: () => api<Paginated<AssetCard>>(`/assets?${qs}&page=${page}`) });
    const set = (k: string, v: string) => { const n = new URLSearchParams(params); v ? n.set(k, v) : n.delete(k); n.delete('page'); setParams(n, { replace: true }); };
    const canEdit = can('assets.edit');
    const canDownload = can('assets.download_original');
    const downloadSelected = async () => {
        if (selected.length === 1) {
            const r = await api<{ url: string }>(`/assets/${selected[0]}/download`, { method: 'POST' });
            window.location.href = r.url;
            return;
        }
        await ensureCsrf();
        const token = csrfToken();
        if (token) submitZipDownload('/api/downloads', token, selected);
    };
    const bulk = useMutation({
        mutationFn: () => api('/assets-bulk', { method: 'POST', json: { uuids: selected, album_uuid: bulkAlbum || null, add_tags: bulkTags.split(',').map((t) => t.trim()).filter(Boolean) } }),
        onSuccess: () => { setSelected([]); setBulkTags(''); qc.invalidateQueries({ queryKey: ['assets'] }); qc.invalidateQueries({ queryKey: ['albums'] }); },
    });
    const active = FILTERS.filter((f) => f !== 'sort' && params.get(f)).length;

    const filters = (
        <div className="space-y-3">
            <Field label="Type"><Select value={params.get('kind') || ''} onChange={(e) => set('kind', e.target.value)}><option value="">All</option>{['image', 'video', 'audio', 'document', 'other'].map((k) => <option key={k}>{k}</option>)}</Select></Field>
            <Field label="Review state"><Select value={params.get('review_state') || ''} onChange={(e) => set('review_state', e.target.value)}><option value="">All</option>{['unreviewed', 'approved', 'published', 'restricted', 'archived'].map((k) => <option key={k}>{k}</option>)}</Select></Field>
            <Field label="Project"><Select value={params.get('project_id') || ''} onChange={(e) => set('project_id', e.target.value)}><option value="">All</option>{lk?.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
            <Field label="Event"><Select value={params.get('event_id') || ''} onChange={(e) => set('event_id', e.target.value)}><option value="">All</option>{lk?.events.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</Select></Field>
            <Field label="Cohort"><Select value={params.get('cohort_id') || ''} onChange={(e) => set('cohort_id', e.target.value)}><option value="">All</option>{lk?.cohorts.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
            <Field label="Category"><Select value={params.get('category_id') || ''} onChange={(e) => set('category_id', e.target.value)}><option value="">All</option>{lk?.categories.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
            <Field label="Album"><Select value={params.get('album') || ''} onChange={(e) => set('album', e.target.value)}><option value="">All</option>{lk?.albums.map((p) => <option key={p.uuid} value={p.uuid}>{p.title}</option>)}</Select></Field>
            <Field label="Tag"><Select value={params.get('tag') || ''} onChange={(e) => set('tag', e.target.value)}><option value="">All</option>{lk?.tags.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}</Select></Field>
            <div className="grid grid-cols-2 gap-2">
                <Field label="Uploaded from"><Input type="date" value={params.get('date_from') || ''} onChange={(e) => set('date_from', e.target.value)} /></Field>
                <Field label="to"><Input type="date" value={params.get('date_to') || ''} onChange={(e) => set('date_to', e.target.value)} /></Field>
            </div>
            {active ? <Button variant="link" size="sm" onClick={() => setParams({})}>Clear filters</Button> : null}
        </div>
    );

    return (
        <>
            <PageHeader title="Library" description={q.data ? `${q.data.total} item${q.data.total === 1 ? '' : 's'}` : 'Loading…'}
                actions={<>
                    <Input className="w-full sm:w-64" placeholder="Search title, file, credit, tag…" defaultValue={params.get('q') || ''} onKeyDown={(e) => e.key === 'Enter' && set('q', (e.target as HTMLInputElement).value)} onBlur={(e) => set('q', e.target.value)} />
                    <Select className="w-36" value={params.get('sort') || 'newest'} onChange={(e) => set('sort', e.target.value)} aria-label="Sort"><option value="newest">Newest</option><option value="oldest">Oldest</option><option value="captured">Date taken</option><option value="title">Title</option><option value="largest">Largest</option></Select>
                    <Button variant="secondary" className="lg:hidden" onClick={() => setShowFilters(true)}><SlidersHorizontal className="h-4 w-4" />Filters{active ? ` (${active})` : ''}</Button>
                </>} />
            <div className="grid gap-5 lg:grid-cols-[230px_1fr]">
                <Card className="hidden h-fit p-4 lg:block">{filters}</Card>
                <div>
                    {selected.length ? (
                        <Card className="mb-3 flex flex-wrap items-end gap-2 border-lia-200 bg-lia-50/50 p-3">
                            <span className="text-sm font-medium">{selected.length} selected</span>
                            {can('albums.manage') ? <Select className="h-9 w-48" value={bulkAlbum} onChange={(e) => setBulkAlbum(e.target.value)} aria-label="Add to album"><option value="">Add to album…</option>{lk?.albums.map((a) => <option key={a.uuid} value={a.uuid}>{a.title}</option>)}</Select> : null}
                            {canEdit ? <Input className="h-9 w-48" placeholder="Add tags" value={bulkTags} onChange={(e) => setBulkTags(e.target.value)} /> : null}
                            {canEdit ? <Button size="sm" disabled={bulk.isPending || (!bulkAlbum && !bulkTags)} onClick={() => bulk.mutate()}>Apply</Button> : null}
                            {canDownload ? <Button size="sm" onClick={() => void downloadSelected()}><Download className="h-4 w-4" />Download</Button> : null}
                            <Button size="sm" variant="ghost" onClick={() => setSelected(q.data?.data.map((a) => a.uuid) ?? [])}>All on page</Button>
                            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
                        </Card>
                    ) : null}
                    {q.data && q.data.data.length === 0 ? <EmptyState title="Nothing found">Try fewer filters, or upload new media.</EmptyState> : null}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {(q.data?.data ?? []).map((a) => <AssetTile key={a.uuid} asset={a} selected={selected.includes(a.uuid)} onToggle={canEdit || canDownload ? () => setSelected((s) => (s.includes(a.uuid) ? s.filter((x) => x !== a.uuid) : [...s, a.uuid])) : undefined} />)}
                    </div>
                    {q.data && q.data.last_page > 1 ? (
                        <div className="mt-4 flex items-center gap-2 text-sm">
                            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => { const n = new URLSearchParams(params); n.set('page', String(page - 1)); setParams(n); }}>Previous</Button>
                            <span className="text-neutral-500">Page {page} of {q.data.last_page}</span>
                            <Button size="sm" variant="secondary" disabled={page >= q.data.last_page} onClick={() => { const n = new URLSearchParams(params); n.set('page', String(page + 1)); setParams(n); }}>Next</Button>
                        </div>
                    ) : null}
                </div>
            </div>
            <div className={cn('fixed inset-0 z-30 bg-black/30 lg:hidden', showFilters ? '' : 'hidden')} onClick={() => setShowFilters(false)}>
                <div className="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-white p-4" onClick={(e) => e.stopPropagation()}>
                    <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Filters</h2><button aria-label="Close filters" onClick={() => setShowFilters(false)}><X className="h-5 w-5" /></button></div>
                    {filters}
                </div>
            </div>
        </>
    );
}
