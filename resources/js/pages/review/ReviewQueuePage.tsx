import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import { Button } from '@/components/ui/button';
import { EmptyState, PageHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { ACTION_LABEL, allowedActions } from '@/lib/review';
import type { AssetCard, Paginated, ReviewState } from '@/lib/types';
import { cn } from '@/lib/utils';

const TABS: { key: string; label: string; qs: string; from: ReviewState | null }[] = [
    { key: 'unreviewed', label: 'To review', qs: 'review_state=unreviewed', from: 'unreviewed' },
    { key: 'approved', label: 'Approved, not published', qs: 'review_state=approved', from: 'approved' },
    { key: 'consent', label: 'Consent missing', qs: 'consent_status=missing', from: null },
    { key: 'restricted', label: 'Restricted', qs: 'review_state=restricted', from: 'restricted' },
];

export default function ReviewQueuePage() {
    const { can } = useAuth();
    const qc = useQueryClient();
    const [tab, setTab] = useState(TABS[0]);
    const [selected, setSelected] = useState<string[]>([]);
    const [note, setNote] = useState('');
    const [result, setResult] = useState('');
    const q = useQuery({ queryKey: ['assets', 'review', tab.key], queryFn: () => api<Paginated<AssetCard>>(`/assets?${tab.qs}&per_page=60`) });
    const bulk = useMutation({
        mutationFn: (to: ReviewState) => api<{ updated: number; failed: { title: string; error: string }[] }>('/review/bulk', { method: 'POST', json: { uuids: selected, to_state: to, note: note || null } }),
        onSuccess: (r) => {
            setResult(`${r.updated} updated` + (r.failed.length ? ` · ${r.failed.length} skipped: ${r.failed.map((f) => `${f.title} (${f.error})`).join('; ')}` : ''));
            setSelected([]); setNote(''); qc.invalidateQueries({ queryKey: ['assets'] }); qc.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });
    const items = q.data?.data ?? [];
    const actions = tab.from ? allowedActions(tab.from, can) : [];
    const toggle = (u: string) => setSelected((s) => (s.includes(u) ? s.filter((x) => x !== u) : [...s, u]));
    return (
        <>
            <PageHeader title="Review" description="Approve, publish or restrict new material. Only approved and published items can be shared outside the team." />
            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-neutral-200">
                {TABS.map((t) => <button key={t.key} onClick={() => { setTab(t); setSelected([]); setResult(''); }} className={cn('whitespace-nowrap border-b-2 px-3 py-2 text-sm', t.key === tab.key ? 'border-lia-600 font-medium text-lia-700' : 'border-transparent text-neutral-500 hover:text-neutral-800')}>{t.label}</button>)}
            </div>
            {selected.length && actions.length ? (
                <div className="sticky top-14 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 bg-white p-2 shadow-sm">
                    <span className="text-sm">{selected.length} selected</span>
                    <Input className="w-56" placeholder="Note (needed to restrict)" value={note} onChange={(e) => setNote(e.target.value)} aria-label="Bulk note" />
                    {actions.map((to) => <Button key={to} size="sm" variant={to === 'restricted' ? 'secondary' : 'default'} disabled={bulk.isPending} onClick={() => bulk.mutate(to)}>{ACTION_LABEL[to]}</Button>)}
                    <Button size="sm" variant="ghost" onClick={() => setSelected(items.map((a) => a.uuid))}>Select all</Button>
                    <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
                </div>
            ) : null}
            {result ? <p role="status" className="mb-3 text-sm text-neutral-600">{result}</p> : null}
            {q.isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : items.length === 0 ? <EmptyState title="Nothing here">The queue is clear.</EmptyState> : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    {items.map((a) => <AssetTile key={a.uuid} asset={a} selected={selected.includes(a.uuid)} onToggle={actions.length ? () => toggle(a.uuid) : undefined} />)}
                </div>
            )}
        </>
    );
}
