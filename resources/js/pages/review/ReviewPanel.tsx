import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, UserPlus, X } from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { ReviewBadge } from '@/components/media/AssetTile';
import { Button } from '@/components/ui/button';
import { Badge, Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/input';
import { ApiError, api } from '@/lib/api';
import { ACTION_LABEL, allowedActions } from '@/lib/review';
import type { AssetDetail, ReviewState } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const CHANNELS = ['website', 'instagram', 'facebook', 'x', 'youtube', 'tiktok', 'press', 'print', 'report', 'other'];
const consentTone: Record<string, string> = { covered: 'green', missing: 'amber', withdrawn: 'red', unknown: 'neutral', not_needed: 'neutral' };
const msg = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors).flat()[0] || e.message : 'Something went wrong');
type Done = (r: { asset: AssetDetail }) => void;

export default function ReviewPanel({ asset }: { asset: AssetDetail }) {
    const { can } = useAuth();
    const qc = useQueryClient();
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    const done: Done = (r) => { qc.setQueryData(['asset', asset.uuid], r.asset); qc.invalidateQueries({ queryKey: ['assets'] }); setErr(''); };
    const move = useMutation({
        mutationFn: (to: ReviewState) => api<{ asset: AssetDetail }>(`/assets/${asset.uuid}/review`, { method: 'POST', json: { to_state: to, note: note || null } }),
        onSuccess: (r) => { done(r); setNote(''); }, onError: (e) => setErr(msg(e)),
    });
    const actions = allowedActions(asset.review_state, can);
    return (
        <Card>
            <CardHeader><CardTitle className="flex flex-wrap items-center gap-2">Review <ReviewBadge state={asset.review_state} /> <Badge tone={consentTone[asset.consent_status]}>consent: {asset.consent_status.replace('_', ' ')}</Badge></CardTitle></CardHeader>
            <CardContent className="space-y-4">
                {asset.review_note ? <p className="rounded-md bg-neutral-50 p-2 text-xs text-neutral-600">“{asset.review_note}” — {asset.reviewer}, {formatDate(asset.reviewed_at)}</p> : null}
                {actions.length ? (
                    <div className="space-y-2">
                        <Textarea rows={2} placeholder="Note (required when restricting)" value={note} onChange={(e) => setNote(e.target.value)} aria-label="Review note" />
                        <div className="flex flex-wrap gap-2">{actions.map((to) => <Button key={to} size="sm" variant={to === 'restricted' ? 'secondary' : to === 'archived' || to === 'unreviewed' ? 'ghost' : 'default'} disabled={move.isPending} onClick={() => move.mutate(to)}>{ACTION_LABEL[to]}</Button>)}</div>
                    </div>
                ) : null}
                {err ? <p role="alert" className="rounded-md border border-lia-200 bg-lia-50 p-2 text-xs text-lia-800">{err}</p> : null}
                <People asset={asset} onDone={done} />
                <Publications asset={asset} onDone={done} />
                {asset.review_history.length ? (
                    <details className="text-xs text-neutral-600"><summary className="cursor-pointer font-medium">History ({asset.review_history.length})</summary>
                        <ul className="mt-2 space-y-1">{asset.review_history.map((h, i) => <li key={i}>{formatDate(h.at)} · {h.actor ?? 'system'}: {h.from} → <b>{h.to}</b>{h.note ? ` — ${h.note}` : ''}</li>)}</ul>
                    </details>
                ) : null}
            </CardContent>
        </Card>
    );
}

function People({ asset, onDone }: { asset: AssetDetail; onDone: Done }) {
    const { can } = useAuth();
    const qc = useQueryClient();
    const [pick, setPick] = useState('');
    const [name, setName] = useState('');
    const [minor, setMinor] = useState(false);
    const [err, setErr] = useState('');
    const manage = can('consent.manage');
    const people = useQuery({ queryKey: ['people'], queryFn: () => api<{ data: { id: number; full_name: string }[] }>('/people').then((r) => r.data), enabled: manage });
    const link = useMutation({
        mutationFn: async () => {
            let id = Number(pick);
            if (!id) id = (await api<{ data: { id: number } }>('/people', { method: 'POST', json: { full_name: name, is_minor: minor } })).data.id;
            return api<{ asset: AssetDetail }>(`/assets/${asset.uuid}/people`, { method: 'POST', json: { person_id: id } });
        },
        onSuccess: (r) => { onDone(r); setPick(''); setName(''); setMinor(false); setErr(''); qc.invalidateQueries({ queryKey: ['people'] }); }, onError: (e) => setErr(msg(e)),
    });
    const unlink = useMutation({ mutationFn: (id: number) => api<{ asset: AssetDetail }>(`/assets/${asset.uuid}/people/${id}`, { method: 'DELETE' }), onSuccess: onDone });
    const status = useMutation({ mutationFn: (s: string) => api<{ asset: AssetDetail }>(`/assets/${asset.uuid}/consent-status`, { method: 'POST', json: { consent_status: s } }), onSuccess: onDone });
    return (
        <div className="space-y-2 border-t border-neutral-100 pt-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">People pictured</h3>
            {asset.people.length ? (
                <ul className="flex flex-wrap gap-1.5">{asset.people.map((p) => (
                    <li key={p.id} className="flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs">
                        {p.full_name}{p.is_minor ? ' (minor)' : ''}{p.consent_record_id ? null : <span className="text-amber-700"> · no consent</span>}
                        {manage ? <button aria-label={`Remove ${p.full_name}`} onClick={() => unlink.mutate(p.id)}><X className="h-3 w-3" /></button> : null}
                    </li>))}</ul>
            ) : <p className="text-xs text-neutral-500">Nobody linked.{manage ? <> Mark as <button className="text-lia-700 underline" onClick={() => status.mutate('not_needed')}>no consent needed</button> / <button className="text-lia-700 underline" onClick={() => status.mutate('unknown')}>unknown</button>.</> : null}</p>}
            {manage ? (
                <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (pick || name.trim()) link.mutate(); }}>
                    <Select aria-label="Existing person" value={pick} onChange={(e) => setPick(e.target.value)} className="w-40"><option value="">New person…</option>{people.data?.map((p) => <option key={p.id} value={p.id}>{p.full_name}</option>)}</Select>
                    {!pick ? <><Input aria-label="Full name" placeholder="Full name" className="w-40" value={name} onChange={(e) => setName(e.target.value)} /><label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={minor} onChange={(e) => setMinor(e.target.checked)} /> minor</label></> : null}
                    <Button size="sm" variant="secondary" type="submit" disabled={link.isPending}><UserPlus className="h-3.5 w-3.5" /> Link</Button>
                </form>
            ) : null}
            {err ? <p role="alert" className="text-xs text-lia-700">{err}</p> : null}
        </div>
    );
}

function Publications({ asset, onDone }: { asset: AssetDetail; onDone: Done }) {
    const { can } = useAuth();
    const [f, setF] = useState({ channel: 'instagram', url: '', published_on: new Date().toISOString().slice(0, 10) });
    const [err, setErr] = useState('');
    const add = useMutation({
        mutationFn: () => api<{ asset: AssetDetail }>(`/assets/${asset.uuid}/publications`, { method: 'POST', json: { ...f, url: f.url || null } }),
        onSuccess: (r) => { onDone(r); setF({ ...f, url: '' }); setErr(''); }, onError: (e) => setErr(msg(e)),
    });
    const canAdd = asset.review_state === 'published' && can('publications.manage');
    if (!asset.publications.length && !canAdd) return null;
    return (
        <div className="space-y-2 border-t border-neutral-100 pt-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">Where it was used</h3>
            <ul className="space-y-1 text-xs">{asset.publications.map((p) => (
                <li key={p.id}><Badge>{p.channel}</Badge> {formatDate(p.published_on)} {p.url ? <a className="inline-flex items-center gap-0.5 text-lia-700 hover:underline" href={p.url} target="_blank" rel="noreferrer noopener">link <ExternalLink className="h-3 w-3" /></a> : null} <span className="text-neutral-400">· {p.recorded_by}</span></li>))}</ul>
            {canAdd ? (
                <form className="grid grid-cols-2 gap-2 sm:grid-cols-[120px_1fr_140px_auto]" onSubmit={(e) => { e.preventDefault(); add.mutate(); }} noValidate>
                    <Field label="Channel"><Select value={f.channel} onChange={(e) => setF({ ...f, channel: e.target.value })}>{CHANNELS.map((c) => <option key={c}>{c}</option>)}</Select></Field>
                    <Field label="URL"><Input type="url" value={f.url} onChange={(e) => setF({ ...f, url: e.target.value })} placeholder="https://" /></Field>
                    <Field label="Date"><Input type="date" value={f.published_on} onChange={(e) => setF({ ...f, published_on: e.target.value })} /></Field>
                    <div className="flex items-end"><Button size="sm" type="submit" disabled={add.isPending}>Record</Button></div>
                </form>
            ) : null}
            {err ? <p role="alert" className="text-xs text-lia-700">{err}</p> : null}
        </div>
    );
}
