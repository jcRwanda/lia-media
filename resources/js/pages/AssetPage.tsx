import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Download, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useParams } from 'react-router';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { ReviewBadge } from '@/components/media/AssetTile';
import { Button } from '@/components/ui/button';
import { Badge, Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { api, fieldErrors } from '@/lib/api';
import type { AssetDetail } from '@/lib/types';
import { formatBytes, formatDate } from '@/lib/utils';
import ReviewPanel from '@/pages/review/ReviewPanel';
import ShareDialog from '@/pages/shares/ShareDialog';

const schema = z.object({
    title: z.string().min(1, 'Title is required').max(190),
    description: z.string().max(5000).optional(),
    credit: z.string().max(190).optional(),
    project_id: z.string().optional(),
    event_id: z.string().optional(),
    cohort_id: z.string().optional(),
    category_id: z.string().optional(),
    tags: z.string().optional(),
});
type Form = z.infer<typeof schema>;
const idOrNull = (v?: string) => (v ? Number(v) : null);

export default function AssetPage() {
    const { uuid } = useParams();
    const { can } = useAuth();
    const nav = useNavigate();
    const qc = useQueryClient();
    const lk = useLookups().data;
    const [saved, setSaved] = useState('');
    const q = useQuery({ queryKey: ['asset', uuid], queryFn: () => api<{ asset: AssetDetail }>(`/assets/${uuid}`).then((r) => r.asset) });
    const a = q.data;
    const form = useForm<Form>({ resolver: zodResolver(schema) });
    useEffect(() => {
        if (a) form.reset({ title: a.title, description: a.description ?? '', credit: a.credit ?? '', project_id: a.project_id?.toString() ?? '', event_id: a.event_id?.toString() ?? '', cohort_id: a.cohort_id?.toString() ?? '', category_id: a.category_id?.toString() ?? '', tags: a.tags.join(', ') });
    }, [a?.uuid, a?.title, a?.tags.join()]); // eslint-disable-line react-hooks/exhaustive-deps
    const save = useMutation({
        mutationFn: (v: Form) => api<{ asset: AssetDetail }>(`/assets/${uuid}`, { method: 'PATCH', json: { ...v, project_id: idOrNull(v.project_id), event_id: idOrNull(v.event_id), cohort_id: idOrNull(v.cohort_id), category_id: idOrNull(v.category_id), tags: (v.tags || '').split(',').map((t) => t.trim()).filter(Boolean) } }),
        onSuccess: (r) => { qc.setQueryData(['asset', uuid], r.asset); qc.invalidateQueries({ queryKey: ['assets'] }); setSaved('Saved.'); },
        onError: (e) => { setSaved(''); Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError(k as keyof Form, { message: m })); },
    });
    const download = useMutation({ mutationFn: () => api<{ url: string }>(`/assets/${uuid}/download`, { method: 'POST' }), onSuccess: (r) => { window.location.href = r.url; } });
    const del = useMutation({ mutationFn: () => api(`/assets/${uuid}`, { method: 'DELETE' }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['assets'] }); nav('/library'); } });

    if (q.isLoading) return <p className="text-sm text-neutral-500">Loading…</p>;
    if (!a) return <p className="text-sm text-lia-700">Not found.</p>;
    const editable = can('assets.edit');
    const e = form.formState.errors;
    return (
        <>
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Link to="/library" className="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-lia-700"><ArrowLeft className="h-4 w-4" />Library</Link>
                <h1 className="min-w-0 flex-1 truncate text-lg font-semibold">{a.title}</h1>
                <ReviewBadge state={a.review_state} />
                {can('shares.manage') ? <ShareDialog target={{ type: 'asset', uuid: a.uuid, title: a.title }} /> : null}
                {can('assets.download_original') ? <Button size="sm" variant="secondary" onClick={() => download.mutate()} disabled={download.isPending}><Download className="h-4 w-4" />Original</Button> : null}
                {can('assets.delete') ? <Button size="sm" variant="destructive" onClick={() => window.confirm('Move this asset to trash? The original stays in storage.') && del.mutate()}><Trash2 className="h-4 w-4" /></Button> : null}
            </div>
            <div className="grid gap-5 lg:grid-cols-[1fr_360px]">
                <div className="space-y-5">
                    <Card className="overflow-hidden">
                        <div className="grid min-h-64 place-items-center bg-neutral-900">
                            {a.preview_url ? <img src={a.preview_url} alt={a.title} className="max-h-[70vh] w-auto object-contain" /> : <p className="p-10 text-sm text-neutral-400">No preview yet</p>}
                        </div>
                        <div className="flex flex-wrap gap-x-4 gap-y-1 p-3 text-xs text-neutral-500">
                            <span>{a.original_filename}</span><span>{formatBytes(a.size_bytes)}</span>{a.width ? <span>{a.width}×{a.height}</span> : null}
                            {a.duration_ms ? <span>{Math.round(a.duration_ms / 1000)} s</span> : null}<span>{a.mime_type}</span>
                            <span>Uploaded {formatDate(a.created_at)} by {a.uploader ?? '—'}</span>
                            <span title={a.sha256}>SHA-256 {a.sha256.slice(0, 12)}… {a.metadata?.checksum_verified ? <Badge tone="green">verified</Badge> : <Badge tone="amber">pending</Badge>}</span>
                            {a.storage_status !== 'stored' ? <Badge tone="red">{a.storage_status}</Badge> : null}
                        </div>
                    </Card>
                    <ReviewPanel asset={a} />
                </div>
                <Card>
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent>
                        <form className="space-y-3" onSubmit={form.handleSubmit((v) => save.mutate(v))} noValidate>
                            <fieldset disabled={!editable} className="space-y-3">
                                <Field label="Title" error={e.title?.message}><Input {...form.register('title')} /></Field>
                                <Field label="Description" error={e.description?.message}><Textarea rows={3} {...form.register('description')} /></Field>
                                <Field label="Credit" error={e.credit?.message}><Input {...form.register('credit')} /></Field>
                                <Field label="Project"><Select {...form.register('project_id')}><option value="">—</option>{lk?.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                                <Field label="Event"><Select {...form.register('event_id')}><option value="">—</option>{lk?.events.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</Select></Field>
                                <Field label="Cohort"><Select {...form.register('cohort_id')}><option value="">—</option>{lk?.cohorts.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                                <Field label="Category"><Select {...form.register('category_id')}><option value="">—</option>{lk?.categories.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                                <Field label="Tags (comma separated)"><Input {...form.register('tags')} /></Field>
                            </fieldset>
                            {editable ? <div className="flex items-center gap-3"><Button type="submit" disabled={save.isPending}>Save details</Button>{saved ? <span role="status" className="text-xs text-emerald-700">{saved}</span> : null}</div> : null}
                        </form>
                        {a.albums.length ? <div className="mt-4 text-xs text-neutral-500">In albums: {a.albums.map((al) => <Link key={al.uuid} className="mr-2 text-lia-700 hover:underline" to={`/albums/${al.uuid}`}>{al.title}</Link>)}</div> : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
