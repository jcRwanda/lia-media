import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, ChevronLeft, ChevronRight, Circle, Download, Lock, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useParams } from 'react-router';
import { z } from 'zod';
import { Brand } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, ApiError, csrfToken, ensureCsrf, fieldErrors } from '@/lib/api';
import { fetchSelectedFiles, MAX_ZIP_ITEMS, saveFilesSequentially, shareFiles, stepIndex, submitZipDownload, swipeDirection, toggleSelection, usesIndividualDownloads } from '@/lib/gallery';
import { cn, formatDate } from '@/lib/utils';

interface Item { uuid: string; kind: string; title: string; width: number | null; height: number | null; credit: string | null; thumb_url: string | null; preview_url: string | null }
interface Res { share: { title: string | null; message: string | null; mode: string; allow_download: boolean; expires_at: string | null }; requires_code: boolean; items?: Item[]; url_ttl_seconds?: number }

const codeSchema = z.object({ code: z.string().min(4, 'Enter the code you received') });

export default function VisitorGalleryPage() {
    const { token } = useParams();
    const qc = useQueryClient();
    const [view, setView] = useState<number | null>(null);
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [individual] = useState(usesIndividualDownloads);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const touch = useRef<{ x: number; y: number } | null>(null);
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
    const allowDownload = !!q.data?.share.allow_download;
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (view === null) return;
            if (e.key === 'Escape') setView(null);
            if (e.key === 'ArrowRight') setView((v) => (v === null ? v : stepIndex(v, 1, items.length)));
            if (e.key === 'ArrowLeft') setView((v) => (v === null ? v : stepIndex(v, -1, items.length)));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [view, items.length]);
    useEffect(() => {
        document.body.style.overflow = view === null ? '' : 'hidden';

        return () => { document.body.style.overflow = ''; };
    }, [view]);

    const endSelect = () => { setSelecting(false); setSelected([]); setNotice(null); };
    /** One item downloads directly; several use a zip on desktop and per-file sharing on phones. */
    const downloadSelected = async () => {
        if (!selected.length || busy) return;
        if (selected.length === 1) return download.mutate(selected[0]);
        if (individual) {
            setBusy(true);
            setNotice(null);
            try {
                const files = await fetchSelectedFiles(`/api/s/${token}`, selected, (uuid) => items.find((it) => it.uuid === uuid)?.title ?? 'photo');
                const outcome = await shareFiles(files);
                if (outcome === 'unsupported') saveFilesSequentially(files);
                if (outcome === 'shared') endSelect();
            } catch (e) {
                setNotice((e as Error).message || 'Could not prepare the downloads.');
            } finally {
                setBusy(false);
            }
            return;
        }
        await ensureCsrf();
        const t = csrfToken();
        if (t) submitZipDownload(`/api/s/${token}/download`, t, selected);
    };
    /** Everything, at once: the server streams one zip (desktop only — phones can't unzip reliably). */
    const downloadAll = async () => {
        if (busy || items.length < 2) return;
        setBusy(true);
        setNotice(null);
        try {
            await ensureCsrf();
            const t = csrfToken();
            if (t) submitZipDownload(`/api/s/${token}/download`, t, items.map((it) => it.uuid));
        } catch (e) {
            setNotice((e as Error).message || 'Could not prepare the download.');
        } finally {
            setBusy(false);
        }
    };
    const swipe = (dx: number, dy: number) => {
        const dir = swipeDirection(dx, dy);
        if (dir === 'next') setView((v) => (v === null ? v : stepIndex(v, 1, items.length)));
        if (dir === 'prev') setView((v) => (v === null ? v : stepIndex(v, -1, items.length)));
    };

    const err = q.error instanceof ApiError ? q.error : null;
    const current = view !== null ? items[view] : null;
    const tooMany = !individual && selected.length > MAX_ZIP_ITEMS;
    return (
        <div className="min-h-screen bg-neutral-50">
            <header className="border-b border-neutral-200 bg-white"><div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3"><Brand /><span className="text-xs text-neutral-500">Shared with you</span></div></header>
            <main className="mx-auto max-w-6xl px-4 py-6 pb-24">
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
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <h1 className="text-xl font-semibold">{q.data.share.title}</h1>
                                {q.data.share.message ? <p className="mt-1 max-w-2xl text-sm text-neutral-600">{q.data.share.message}</p> : null}
                                <p className="mt-1 text-xs text-neutral-400">{items.length} item{items.length === 1 ? '' : 's'}{q.data.share.expires_at ? ` · available until ${formatDate(q.data.share.expires_at)}` : ''}</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {allowDownload && items.length > 1 && !selecting && !individual ? (
                                    <Button size="sm" variant="secondary" disabled={busy || items.length > MAX_ZIP_ITEMS} title={items.length > MAX_ZIP_ITEMS ? `A share can stream at most ${MAX_ZIP_ITEMS} items in one zip` : undefined} onClick={() => void downloadAll()}>
                                        <Download className="h-4 w-4" />{busy ? 'Preparing…' : 'Download all'}
                                    </Button>
                                ) : null}
                                {allowDownload && items.length && !selecting ? (
                                    <Button size="sm" variant="secondary" onClick={() => setSelecting(true)}><CheckCircle2 className="h-4 w-4" />Select</Button>
                                ) : null}
                            </div>
                        </div>
                        {notice && !selecting ? <p role="alert" className="mt-2 rounded-lg bg-lia-50 p-2 text-sm text-lia-700">{notice}</p> : null}
                        <div className="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                            {items.map((it, i) => {
                                const isSelected = selected.includes(it.uuid);
                                return (
                                    <button
                                        key={it.uuid}
                                        onClick={() => (selecting ? setSelected((s) => toggleSelection(s, it.uuid)) : setView(i))}
                                        className={cn('relative overflow-hidden rounded-lg bg-neutral-200 text-left', selecting && isSelected && 'ring-2 ring-lia-600')}
                                        aria-label={selecting ? (isSelected ? `Deselect ${it.title}` : `Select ${it.title}`) : it.title}
                                    >
                                        {it.thumb_url
                                            ? <img src={it.thumb_url} alt="" loading="lazy" decoding="async" width={it.width ?? 480} height={it.height ?? 360} className="aspect-[4/3] w-full object-cover" />
                                            : <div className="grid aspect-[4/3] place-items-center text-xs text-neutral-500">{it.title}</div>}
                                        {selecting ? (
                                            <span className="absolute left-1.5 top-1.5 rounded-full bg-white/90 p-1 text-lia-700 shadow">
                                                {isSelected ? <CheckCircle2 className="h-5 w-5" /> : <Circle className="h-5 w-5" />}
                                            </span>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                        {!items.length ? <p className="mt-6 text-sm text-neutral-500">Nothing to show here yet.</p> : null}
                    </>
                ) : null}
            </main>
            {q.data && !q.data.requires_code && allowDownload && items.length ? (
                selecting ? (
                    <div className="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
                        <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-2 px-4 py-3">
                            <span className="mr-auto text-sm font-medium">{selected.length} selected</span>
                            <Button size="sm" variant="ghost" onClick={() => setSelected(items.map((it) => it.uuid))}>All</Button>
                            <Button size="sm" variant="ghost" disabled={!selected.length} onClick={() => setSelected([])}>Clear</Button>
                            <Button size="sm" variant="secondary" onClick={endSelect}>Cancel</Button>
                            <Button size="sm" disabled={!selected.length || busy || download.isPending || tooMany} onClick={() => void downloadSelected()}>
                                <Download className="h-4 w-4" />{busy ? 'Preparing…' : 'Download'}
                            </Button>
                        </div>
                        {tooMany ? <p className="mx-auto max-w-6xl px-4 pb-2 text-xs text-lia-700">Select at most {MAX_ZIP_ITEMS} items at a time.</p> : null}
                        {notice ? <p role="alert" className="mx-auto max-w-6xl px-4 pb-2 text-xs text-lia-700">{notice}</p> : null}
                    </div>
                ) : null
            ) : null}
            {current ? (
                <div
                    className="fixed inset-0 z-50 flex flex-col bg-black/95 text-white"
                    role="dialog"
                    aria-label={current.title}
                    onTouchStart={(e) => { const t = e.changedTouches[0]; touch.current = { x: t.clientX, y: t.clientY }; }}
                    onTouchEnd={(e) => {
                        const start = touch.current;
                        touch.current = null;
                        if (!start) return;
                        const t = e.changedTouches[0];
                        swipe(t.clientX - start.x, t.clientY - start.y);
                    }}
                >
                    <div className="flex items-center gap-3 p-3 text-sm">
                        <span className="min-w-0 flex-1 truncate">{current.title}{current.credit ? <span className="text-white/60"> · {current.credit}</span> : null}</span>
                        {allowDownload ? <Button size="sm" variant="secondary" onClick={() => download.mutate(current.uuid)}><Download className="h-4 w-4" />Download</Button> : null}
                        <button aria-label="Close" onClick={() => setView(null)}><X className="h-6 w-6" /></button>
                    </div>
                    <div className="relative flex flex-1 items-center justify-center p-2">
                        {current.preview_url ? <img src={current.preview_url} alt={current.title} className="max-h-full max-w-full object-contain" draggable={false} /> : <p>No preview</p>}
                        <button aria-label="Previous" className="absolute left-2 rounded-full bg-white/10 p-3 disabled:opacity-20" disabled={view === 0} onClick={() => setView((v) => (v === null ? v : stepIndex(v, -1, items.length)))}><ChevronLeft /></button>
                        <button aria-label="Next" className="absolute right-2 rounded-full bg-white/10 p-3 disabled:opacity-20" disabled={view === items.length - 1} onClick={() => setView((v) => (v === null ? v : stepIndex(v, 1, items.length)))}><ChevronRight /></button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
