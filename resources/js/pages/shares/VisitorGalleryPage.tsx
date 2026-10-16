import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Download, Lock, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useParams } from 'react-router';
import { z } from 'zod';
import { Brand } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, ApiError, fieldErrors } from '@/lib/api';
import { formatDate } from '@/lib/utils';

interface Item { uuid: string; kind: string; title: string; width: number | null; height: number | null; credit: string | null; thumb_url: string | null; preview_url: string | null }
interface Res { share: { title: string | null; message: string | null; mode: string; allow_download: boolean; expires_at: string | null }; requires_code: boolean; items?: Item[]; url_ttl_seconds?: number }

const codeSchema = z.object({ code: z.string().min(4, 'Enter the code you received') });

export default function VisitorGalleryPage() {
    const { token } = useParams();
    const qc = useQueryClient();
    const [view, setView] = useState<number | null>(null);
    const q = useQuery({
        queryKey: ['visit', token], queryFn: () => api<Res>(`/s/${token}`), retry: false,
        // Presigned media URLs are short-lived: refresh before they expire.
        refetchInterval: (query) => (query.state.data?.url_ttl_seconds ? (query.state.data.url_ttl_seconds - 30) * 1000 : false),
    });
    const form = useForm<z.infer<typeof codeSchema>>({ resolver: zodResolver(codeSchema), defaultValues: { code: '' } });
    const unlock = useMutation({
        mutationFn: (v: { code: string }) => api(`/s/${token}/unlock`, { method: 'POST', json: v }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['visit', token] }),
        onError: (e) => form.setError('code', { message: fieldErrors(e).code || (e as Error).message }),
    });
    const download = useMutation({ mutationFn: (uuid: string) => api<{ url: string }>(`/s/${token}/items/${uuid}/download`, { method: 'POST' }), onSuccess: (r) => { window.location.href = r.url; } });
    const items = q.data?.items ?? [];
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (view === null) return;
            if (e.key === 'Escape') setView(null);
            if (e.key === 'ArrowRight') setView((v) => (v === null ? v : Math.min(items.length - 1, v + 1)));
            if (e.key === 'ArrowLeft') setView((v) => (v === null ? v : Math.max(0, v - 1)));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [view, items.length]);

    const err = q.error instanceof ApiError ? q.error : null;
    const current = view !== null ? items[view] : null;
    return (
        <div className="min-h-screen bg-neutral-50">
            <header className="border-b border-neutral-200 bg-white"><div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3"><Brand /><span className="text-xs text-neutral-500">Shared with you</span></div></header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                {q.isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
                {err ? <Card className="mx-auto max-w-md p-6 text-center"><p className="font-medium">{err.status === 410 || err.status === 404 || err.status === 403 ? err.message : 'Something went wrong.'}</p><p className="mt-1 text-sm text-neutral-500">Ask the person who shared it for a new link.</p></Card> : null}
                {q.data?.requires_code ? (
                    <Card className="mx-auto max-w-sm p-6">
                        <Lock className="h-6 w-6 text-lia-600" />
                        <h1 className="mt-3 text-lg font-semibold">{q.data.share.title}</h1>
                        <p className="text-sm text-neutral-500">Enter the access code you were given.</p>
                        <form className="mt-4 space-y-3" onSubmit={form.handleSubmit((v) => unlock.mutate(v))} noValidate>
                            <Field label="Access code" error={form.formState.errors.code?.message}><Input autoComplete="one-time-code" autoCapitalize="characters" className="font-mono tracking-widest" {...form.register('code')} /></Field>
                            <Button type="submit" className="w-full" disabled={unlock.isPending}>Open</Button>
                        </form>
                    </Card>
                ) : null}
                {q.data && !q.data.requires_code ? (
                    <>
                        <h1 className="text-xl font-semibold">{q.data.share.title}</h1>
                        {q.data.share.message ? <p className="mt-1 max-w-2xl text-sm text-neutral-600">{q.data.share.message}</p> : null}
                        <p className="mt-1 text-xs text-neutral-400">{items.length} item{items.length === 1 ? '' : 's'}{q.data.share.expires_at ? ` · available until ${formatDate(q.data.share.expires_at)}` : ''}</p>
                        <div className="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                            {items.map((it, i) => (
                                <button key={it.uuid} onClick={() => setView(i)} className="overflow-hidden rounded-lg bg-neutral-200 text-left">
                                    {it.thumb_url ? <img src={it.thumb_url} alt={it.title} loading="lazy" className="aspect-[4/3] w-full object-cover" /> : <div className="grid aspect-[4/3] place-items-center text-xs text-neutral-500">{it.title}</div>}
                                </button>
                            ))}
                        </div>
                        {!items.length ? <p className="mt-6 text-sm text-neutral-500">Nothing to show here yet.</p> : null}
                    </>
                ) : null}
            </main>
            {current ? (
                <div className="fixed inset-0 z-50 flex flex-col bg-black/95 text-white" role="dialog" aria-label={current.title}>
                    <div className="flex items-center gap-3 p-3 text-sm">
                        <span className="min-w-0 flex-1 truncate">{current.title}{current.credit ? <span className="text-white/60"> · {current.credit}</span> : null}</span>
                        {q.data?.share.allow_download ? <Button size="sm" variant="secondary" onClick={() => download.mutate(current.uuid)}><Download className="h-4 w-4" />Download</Button> : null}
                        <button aria-label="Close" onClick={() => setView(null)}><X className="h-6 w-6" /></button>
                    </div>
                    <div className="relative flex flex-1 items-center justify-center p-2">
                        {current.preview_url ? <img src={current.preview_url} alt={current.title} className="max-h-full max-w-full object-contain" /> : <p>No preview</p>}
                        <button aria-label="Previous" className="absolute left-2 rounded-full bg-white/10 p-2 disabled:opacity-20" disabled={view === 0} onClick={() => setView((v) => (v! > 0 ? v! - 1 : v))}><ChevronLeft /></button>
                        <button aria-label="Next" className="absolute right-2 rounded-full bg-white/10 p-2 disabled:opacity-20" disabled={view === items.length - 1} onClick={() => setView((v) => (v! < items.length - 1 ? v! + 1 : v))}><ChevronRight /></button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
