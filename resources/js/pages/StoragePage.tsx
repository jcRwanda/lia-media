import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatBytes, formatDate } from '@/lib/utils';

type Storage = {
    originals_bytes: number; derivatives_bytes: number; total_bytes: number; trashed_bytes: number; asset_count: number;
    by_kind: { kind: string; count: number; bytes: number }[]; by_month: { month: string; count: number; bytes: number }[];
    estimated_monthly_usd: number; monthly_budget_usd: number; price_per_gb_month: number; free_gb: number;
    last_snapshot: { at: string; objects: number; bytes: number; estimated_monthly_usd: number } | null;
};

export default function StoragePage() {
    const { can } = useAuth();
    const qc = useQueryClient();
    const q = useQuery({ queryKey: ['storage'], queryFn: () => api<Storage>('/storage') });
    const snap = useMutation({ mutationFn: () => api('/storage/snapshot', { method: 'POST' }), onSuccess: () => qc.invalidateQueries({ queryKey: ['storage'] }) });
    const s = q.data;
    if (!s) return <p className="text-sm text-neutral-500">Loading…</p>;
    const pct = s.monthly_budget_usd > 0 ? Math.min(100, (s.estimated_monthly_usd / s.monthly_budget_usd) * 100) : 0;
    const maxMonth = Math.max(1, ...s.by_month.map((m) => m.bytes));
    return (
        <>
            <PageHeader title="Storage" description={`Cloudflare R2 · $${s.price_per_gb_month}/GB-month after ${s.free_gb} GB free · no egress fees`}
                actions={can('settings.manage') ? <Button size="sm" variant="secondary" onClick={() => snap.mutate()} disabled={snap.isPending}><RefreshCw className="h-3.5 w-3.5" /> Count bucket now</Button> : null} />
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader><CardTitle>Usage</CardTitle></CardHeader>
                    <CardContent className="space-y-1 text-sm">
                        <div className="text-3xl font-semibold">{formatBytes(s.total_bytes)}</div>
                        <div className="text-neutral-500">{s.asset_count} items · originals {formatBytes(s.originals_bytes)} · previews {formatBytes(s.derivatives_bytes)}</div>
                        {s.trashed_bytes ? <div className="text-neutral-500">Includes {formatBytes(s.trashed_bytes)} in trash</div> : null}
                        <div className="pt-3 text-xs text-neutral-500">Estimated ${s.estimated_monthly_usd.toFixed(2)} of ${s.monthly_budget_usd.toFixed(2)} monthly budget</div>
                        <div className="h-2 rounded-full bg-neutral-100" role="progressbar" aria-valuenow={Math.round(pct)}><div className={`h-2 rounded-full ${pct > 90 ? 'bg-lia-600' : 'bg-lia-400'}`} style={{ width: `${pct}%` }} /></div>
                        {s.last_snapshot ? <div className="pt-3 text-xs text-neutral-500">Bucket count {formatDate(s.last_snapshot.at)}: {s.last_snapshot.objects} objects, {formatBytes(s.last_snapshot.bytes)}</div> : <div className="pt-3 text-xs text-neutral-500">The bucket is counted daily at 02:30.</div>}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>By type</CardTitle></CardHeader>
                    <CardContent><table className="w-full text-sm"><tbody>{s.by_kind.map((k) => <tr key={k.kind} className="border-b border-neutral-100 last:border-0"><td className="py-1.5 capitalize">{k.kind}</td><td className="text-right text-neutral-500">{k.count}</td><td className="text-right">{formatBytes(k.bytes)}</td></tr>)}</tbody></table></CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Added per month</CardTitle></CardHeader>
                    <CardContent className="space-y-1.5">{s.by_month.length ? s.by_month.map((m) => (
                        <div key={m.month} className="flex items-center gap-2 text-xs"><span className="w-14 text-neutral-500">{m.month}</span><div className="h-2 flex-1 rounded bg-neutral-100"><div className="h-2 rounded bg-lia-400" style={{ width: `${(m.bytes / maxMonth) * 100}%` }} /></div><span className="w-16 text-right">{formatBytes(m.bytes)}</span></div>
                    )) : <p className="text-sm text-neutral-500">No uploads yet.</p>}</CardContent>
                </Card>
            </div>
        </>
    );
}
