import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, Images } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/button';
import { Badge, Card, EmptyState, PageHeader } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { AlbumSummary } from '@/lib/types';

const schema = z.object({ title: z.string().min(1, 'Title is required').max(190), description: z.string().optional(), event_id: z.string().optional() });

function AlbumCard({ album }: { album: AlbumSummary }) {
    return (
        <Link to={`/albums/${album.uuid}`} className={cn('relative overflow-hidden rounded-xl border bg-white hover:border-lia-200', album.is_today ? 'border-lia-400 ring-2 ring-lia-200' : 'border-neutral-200')}>
            {album.is_today ? <span className="absolute left-2 top-2 z-10"><Badge tone="green">Today</Badge></span> : null}
            {album.is_default ? <span className="absolute right-2 top-2 z-10"><Badge tone="amber">Default</Badge></span> : null}
            <div className="aspect-video bg-neutral-100">
                {album.cover_url ? <img src={album.cover_url} alt="" className="h-full w-full object-cover" /> : (
                    <div className="grid h-full place-items-center text-neutral-300">{album.kind === 'daily' ? <CalendarDays className="h-8 w-8" /> : <Images className="h-8 w-8" />}</div>
                )}
            </div>
            <div className="p-3">
                <div className="truncate text-sm font-medium">{album.title}</div>
                <div className="text-xs text-neutral-500">{album.asset_count} item{album.asset_count === 1 ? '' : 's'}{album.event ? ` · ${album.event}` : ''}</div>
            </div>
        </Link>
    );
}

export default function AlbumsPage() {
    const { can } = useAuth();
    const qc = useQueryClient();
    const lk = useLookups().data;
    const [open, setOpen] = useState(false);
    const q = useQuery({ queryKey: ['albums'], queryFn: () => api<{ data: AlbumSummary[] }>('/albums') });
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { title: '', description: '', event_id: '' } });
    const create = useMutation({
        mutationFn: (v: z.infer<typeof schema>) => api('/albums', { method: 'POST', json: { ...v, event_id: v.event_id ? Number(v.event_id) : null } }),
        onSuccess: () => { form.reset(); setOpen(false); qc.invalidateQueries({ queryKey: ['albums'] }); qc.invalidateQueries({ queryKey: ['lookups'] }); },
    });
    const albums = q.data?.data ?? [];
    const dayGalleries = albums.filter((a) => a.kind === 'daily');
    const curated = albums.filter((a) => a.kind !== 'daily');
    return (
        <>
            <PageHeader title="Albums" description="Daily galleries are built automatically as media is uploaded. Curated albums are grouped by hand and can be shared with a link." actions={can('albums.manage') ? <Button onClick={() => setOpen(!open)}>{open ? 'Close' : 'New album'}</Button> : null} />
            {open ? (
                <Card className="mb-5 p-4">
                    <form className="grid gap-3 sm:grid-cols-3" onSubmit={form.handleSubmit((v) => create.mutate(v))} noValidate>
                        <Field label="Title" error={form.formState.errors.title?.message}><Input {...form.register('title')} /></Field>
                        <Field label="Event"><Select {...form.register('event_id')}><option value="">—</option>{lk?.events.map((e) => <option key={e.id} value={e.id}>{e.title}</option>)}</Select></Field>
                        <Field label="Description"><Textarea rows={1} {...form.register('description')} /></Field>
                        <div className="sm:col-span-3"><Button type="submit" disabled={create.isPending}>Create album</Button></div>
                    </form>
                </Card>
            ) : null}
            {q.data && !albums.length ? <EmptyState title="No albums yet" /> : null}
            {q.data && dayGalleries.length ? (
                <section className="mb-7">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><CalendarDays className="h-4 w-4 text-lia-600" /> Day galleries</h2>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                        {dayGalleries.map((al) => <AlbumCard key={al.uuid} album={al} />)}
                    </div>
                </section>
            ) : null}
            {q.data && albums.length ? (
                <section>
                    {dayGalleries.length ? <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Images className="h-4 w-4 text-lia-600" /> Curated albums</h2> : null}
                    {curated.length ? (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {curated.map((al) => <AlbumCard key={al.uuid} album={al} />)}
                        </div>
                    ) : <EmptyState title="No curated albums yet" />}
                </section>
            ) : null}
        </>
    );
}
