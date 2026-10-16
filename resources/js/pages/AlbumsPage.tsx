import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Images } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/button';
import { Card, EmptyState, PageHeader } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { api } from '@/lib/api';
import type { AlbumSummary } from '@/lib/types';

const schema = z.object({ title: z.string().min(1, 'Title is required').max(190), description: z.string().optional(), event_id: z.string().optional() });

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
    return (
        <>
            <PageHeader title="Albums" description="Curated sets of media. Share an album with a link from its page." actions={can('albums.manage') ? <Button onClick={() => setOpen(!open)}>{open ? 'Close' : 'New album'}</Button> : null} />
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
            {q.data && !q.data.data.length ? <EmptyState title="No albums yet" /> : null}
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                {(q.data?.data ?? []).map((al) => (
                    <Link key={al.uuid} to={`/albums/${al.uuid}`} className="overflow-hidden rounded-xl border border-neutral-200 bg-white hover:border-lia-200">
                        <div className="aspect-video bg-neutral-100">{al.cover_url ? <img src={al.cover_url} alt="" className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-neutral-300"><Images className="h-8 w-8" /></div>}</div>
                        <div className="p-3"><div className="truncate text-sm font-medium">{al.title}</div><div className="text-xs text-neutral-500">{al.asset_count} item{al.asset_count === 1 ? '' : 's'}{al.event ? ` · ${al.event}` : ''}</div></div>
                    </Link>
                ))}
            </div>
        </>
    );
}
