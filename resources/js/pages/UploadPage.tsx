import { useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, CopyCheck, FileUp, Pause, Play, RotateCcw, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Badge, Card, PageHeader } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { renditionsFor, uploadRenditions } from '@/lib/derivatives';
import { uploadFile, type Phase, type UploadContext } from '@/lib/uploader';
import { cn, formatBytes } from '@/lib/utils';

interface Item { id: string; file: File; phase: Phase; loaded: number; total: number; message?: string; assetUuid?: string; ctrl?: AbortController }

const tone: Record<Phase, string> = { queued: 'neutral', hashing: 'blue', uploading: 'blue', finalizing: 'blue', derivatives: 'violet', done: 'green', duplicate: 'amber', error: 'red', paused: 'neutral' };
const label: Record<Phase, string> = { queued: 'Queued', hashing: 'Checking', uploading: 'Uploading', finalizing: 'Verifying', derivatives: 'Previews', done: 'Done', duplicate: 'Duplicate', error: 'Failed', paused: 'Paused' };

export default function UploadPage() {
    const lookups = useLookups();
    const qc = useQueryClient();
    const [items, setItems] = useState<Item[]>([]);
    const [ctx, setCtx] = useState<UploadContext & { tagText: string }>({ tagText: '' });
    const [drag, setDrag] = useState(false);
    const running = useRef(0);
    const input = useRef<HTMLInputElement>(null);

    const patch = (id: string, p: Partial<Item>) => setItems((xs) => xs.map((x) => (x.id === id ? { ...x, ...p } : x)));

    const run = useCallback(async (item: Item) => {
        const ctrl = new AbortController();
        patch(item.id, { ctrl, phase: 'hashing', message: undefined });
        running.current++;
        try {
            const r = await renditionsFor(item.file);
            const context: UploadContext = {
                project_id: ctx.project_id || null, event_id: ctx.event_id || null, cohort_id: ctx.cohort_id || null, category_id: ctx.category_id || null,
                album_id: ctx.album_id || null, credit: ctx.credit || null, tags: ctx.tagText.split(',').map((t) => t.trim()).filter(Boolean),
                ...(r ? { width: r.width, height: r.height, duration_ms: 'duration_ms' in r ? (r.duration_ms as number) : undefined } : {}),
            };
            const res = await uploadFile(item.file, context, (p) => patch(item.id, { phase: p.phase, loaded: p.loaded, total: p.total, message: p.message }), ctrl.signal);
            if (!res.duplicate && r) {
                patch(item.id, { phase: 'derivatives' });
                await uploadRenditions(res.assetUuid, r.renditions).catch((e) => patch(item.id, { message: `Previews failed: ${(e as Error).message}` }));
            }
            patch(item.id, { phase: res.duplicate ? 'duplicate' : 'done', assetUuid: res.assetUuid, loaded: item.file.size });
            qc.invalidateQueries({ queryKey: ['assets'] });
        } catch (e) {
            const err = e as Error;
            patch(item.id, err.name === 'AbortError' ? { phase: 'paused', message: 'Paused — resume continues where it stopped.' } : { phase: 'error', message: err.message });
        } finally {
            running.current--;
        }
    }, [ctx, qc]);

    const add = (files: FileList | File[]) => {
        const next = Array.from(files).map((file) => ({ id: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`, file, phase: 'queued' as Phase, loaded: 0, total: file.size }));
        setItems((xs) => [...next, ...xs]);
        next.forEach((it, i) => setTimeout(() => run(it), i * 150));
    };

    const lk = lookups.data;
    const num = (v: string) => (v ? Number(v) : null);
    return (
        <>
            <PageHeader title="Upload center" description="Files go straight to private storage, checked by SHA-256. Duplicates are detected before upload; big files resume after a dropped connection." />
            <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div className="space-y-4">
                    <div
                        onDragOver={(e) => { e.preventDefault(); setDrag(true); }} onDragLeave={() => setDrag(false)}
                        onDrop={(e) => { e.preventDefault(); setDrag(false); add(e.dataTransfer.files); }}
                        onClick={() => input.current?.click()}
                        className={cn('grid cursor-pointer place-items-center rounded-2xl border-2 border-dashed bg-white p-10 text-center transition', drag ? 'border-lia-500 bg-lia-50' : 'border-neutral-300 hover:border-lia-200')}>
                        <FileUp className="h-8 w-8 text-lia-600" />
                        <p className="mt-2 text-sm font-medium">Drop photos, videos, audio or documents</p>
                        <p className="text-xs text-neutral-500">or tap to choose · up to 20 GB per file</p>
                        <input ref={input} type="file" multiple hidden onChange={(e) => e.target.files && add(e.target.files)} />
                    </div>
                    <Card className="divide-y divide-neutral-100">
                        {items.length === 0 ? <p className="p-4 text-sm text-neutral-500">No uploads in this session yet.</p> : null}
                        {items.map((it) => {
                            const pct = it.total ? Math.round((it.loaded / it.total) * 100) : 0;
                            return (
                                <div key={it.id} className="p-3">
                                    <div className="flex items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-sm font-medium">{it.file.name}</div>
                                            <div className="text-xs text-neutral-500">{formatBytes(it.file.size)} · {it.file.type || 'unknown type'}</div>
                                        </div>
                                        <Badge tone={tone[it.phase]}>{label[it.phase]}{['hashing', 'uploading'].includes(it.phase) ? ` ${pct}%` : ''}</Badge>
                                        {['hashing', 'uploading'].includes(it.phase) ? <Button size="icon" variant="ghost" aria-label="Pause" onClick={() => it.ctrl?.abort()}><Pause className="h-4 w-4" /></Button> : null}
                                        {['paused', 'error'].includes(it.phase) ? <Button size="icon" variant="ghost" aria-label="Resume" onClick={() => run(it)}>{it.phase === 'error' ? <RotateCcw className="h-4 w-4" /> : <Play className="h-4 w-4" />}</Button> : null}
                                        {it.assetUuid ? <Link className="text-xs text-lia-700 hover:underline" to={`/assets/${it.assetUuid}`}>{it.phase === 'duplicate' ? <CopyCheck className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}</Link> : null}
                                        {['done', 'duplicate', 'error', 'paused', 'queued'].includes(it.phase) ? <Button size="icon" variant="ghost" aria-label="Remove from list" onClick={() => setItems((xs) => xs.filter((x) => x.id !== it.id))}><X className="h-4 w-4" /></Button> : null}
                                    </div>
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div className={cn('h-full rounded-full transition-all', it.phase === 'error' ? 'bg-lia-500' : it.phase === 'done' ? 'bg-emerald-500' : 'bg-lia-600')} style={{ width: `${it.phase === 'done' || it.phase === 'duplicate' ? 100 : pct}%` }} /></div>
                                    {it.message ? <p className={cn('mt-1 text-xs', it.phase === 'error' ? 'text-lia-700' : 'text-neutral-500')} role={it.phase === 'error' ? 'alert' : undefined}>{it.message}</p> : null}
                                </div>
                            );
                        })}
                    </Card>
                </div>
                <Card className="h-fit space-y-3 p-4">
                    <h2 className="text-sm font-semibold">Apply to new uploads</h2>
                    <Field label="Project"><Select value={ctx.project_id ?? ''} onChange={(e) => setCtx({ ...ctx, project_id: num(e.target.value) })}><option value="">—</option>{lk?.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                    <Field label="Event"><Select value={ctx.event_id ?? ''} onChange={(e) => setCtx({ ...ctx, event_id: num(e.target.value) })}><option value="">—</option>{lk?.events.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</Select></Field>
                    <Field label="Cohort"><Select value={ctx.cohort_id ?? ''} onChange={(e) => setCtx({ ...ctx, cohort_id: num(e.target.value) })}><option value="">—</option>{lk?.cohorts.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                    <Field label="Category"><Select value={ctx.category_id ?? ''} onChange={(e) => setCtx({ ...ctx, category_id: num(e.target.value) })}><option value="">—</option>{lk?.categories.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
                    <Field label="Add to album"><Select value={ctx.album_id ?? ''} onChange={(e) => setCtx({ ...ctx, album_id: num(e.target.value) })}><option value="">—</option>{lk?.albums.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</Select></Field>
                    <Field label="Tags (comma separated)"><Input value={ctx.tagText} onChange={(e) => setCtx({ ...ctx, tagText: e.target.value })} placeholder="stage, graduation" /></Field>
                    <Field label="Credit"><Input value={ctx.credit ?? ''} onChange={(e) => setCtx({ ...ctx, credit: e.target.value })} placeholder="Photographer name" /></Field>
                    <p className="text-xs text-neutral-500">New uploads start as <strong>unreviewed</strong> and never appear in shares until approved.</p>
                </Card>
            </div>
        </>
    );
}
