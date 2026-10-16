import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Badge, Card, EmptyState, PageHeader } from '@/components/ui/card';
import { CopyField } from '@/components/ui/copy';
import { api } from '@/lib/api';
import type { ShareDto } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const statusTone: Record<string, string> = { active: 'green', expired: 'neutral', revoked: 'red', used_up: 'amber' };
const modeLabel: Record<string, string> = { public: 'Public', access_code: 'Access code', restricted: 'Restricted' };

interface Detail { share: ShareDto; recipients: { id: number; email: string; revoked_at: string | null; last_accessed_at: string | null }[]; grants: { active: number; total: number }; attempts: { succeeded: number; failed: number }; log: { action: string; ip: string | null; at: string }[]; url_ttl_seconds: number }

export default function SharesPage() {
    const qc = useQueryClient();
    const [open, setOpen] = useState<string | null>(null);
    const [newUrl, setNewUrl] = useState<string | null>(null);
    const list = useQuery({ queryKey: ['shares'], queryFn: () => api<{ data: ShareDto[] }>('/shares') });
    const detail = useQuery({ queryKey: ['share', open], queryFn: () => api<Detail>(`/shares/${open}`), enabled: !!open });
    const refresh = () => { qc.invalidateQueries({ queryKey: ['shares'] }); qc.invalidateQueries({ queryKey: ['share', open] }); };
    const revoke = useMutation({ mutationFn: (u: string) => api(`/shares/${u}/revoke`, { method: 'POST' }), onSuccess: refresh });
    const rotate = useMutation({ mutationFn: (u: string) => api<{ url: string }>(`/shares/${u}/rotate`, { method: 'POST' }), onSuccess: (r) => { setNewUrl(r.url); refresh(); } });
    const unrecip = useMutation({ mutationFn: ({ u, id }: { u: string; id: number }) => api(`/shares/${u}/recipients/${id}`, { method: 'DELETE' }), onSuccess: refresh });
    return (
        <>
            <PageHeader title="Share links" description="Links to albums and single items. Create new links from an album or asset page." />
            {list.data && !list.data.data.length ? <EmptyState title="No share links yet" /> : null}
            <Card className="divide-y divide-neutral-100">
                {(list.data?.data ?? []).map((s) => (
                    <div key={s.uuid} className="p-3 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <button className="min-w-0 flex-1 text-left" onClick={() => { setOpen(open === s.uuid ? null : s.uuid); setNewUrl(null); }}>
                                <div className="truncate font-medium">{s.title || s.target.title}</div>
                                <div className="text-xs text-neutral-500">{s.target.type} · {s.visible_count} visible · {s.use_count}{s.max_uses ? `/${s.max_uses}` : ''} visitors · expires {s.expires_at ? formatDate(s.expires_at) : 'never'}</div>
                            </button>
                            <Badge>{modeLabel[s.mode]}</Badge>
                            <Badge tone={statusTone[s.status]}>{s.status.replace('_', ' ')}</Badge>
                            {s.status === 'active' ? <Button size="sm" variant="destructive" onClick={() => window.confirm('Revoke this link? Visitors lose access immediately.') && revoke.mutate(s.uuid)}>Revoke</Button> : null}
                        </div>
                        {open === s.uuid && detail.data ? (
                            <div className="mt-3 space-y-3 rounded-lg bg-neutral-50 p-3 text-xs">
                                <div className="flex flex-wrap gap-4">
                                    <span>Active grants: <strong>{detail.data.grants.active}</strong> / {detail.data.grants.total}</span>
                                    {s.mode === 'access_code' ? <span>Code attempts: {detail.data.attempts.succeeded} ok · <span className="text-lia-700">{detail.data.attempts.failed} failed</span></span> : null}
                                    <span>Downloads: {s.allow_download ? (s.include_originals ? 'originals' : 'previews') : 'off'}</span>
                                    {s.target.uuid ? <Link className="text-lia-700 hover:underline" to={s.target.type === 'album' ? `/albums/${s.target.uuid}` : `/assets/${s.target.uuid}`}>Open {s.target.type}</Link> : null}
                                </div>
                                <p className="text-neutral-500">After revoking, media URLs already handed to a visitor stop working within {Math.round(detail.data.url_ttl_seconds / 60)} minutes.</p>
                                {s.status === 'active' && s.mode !== 'restricted' ? <Button size="sm" variant="secondary" onClick={() => window.confirm('Issue a new link? The old link stops working.') && rotate.mutate(s.uuid)}>Rotate link</Button> : null}
                                {newUrl ? <CopyField label="New link (shown once)" value={newUrl} /> : null}
                                {detail.data.recipients.length ? (
                                    <div><div className="mb-1 font-medium">Recipients</div>{detail.data.recipients.map((r) => (
                                        <div key={r.id} className="flex items-center justify-between py-1"><span>{r.email} {r.revoked_at ? <Badge tone="red">revoked</Badge> : null}<span className="ml-2 text-neutral-400">{r.last_accessed_at ? `opened ${formatDate(r.last_accessed_at)}` : 'not opened'}</span></span>
                                            {!r.revoked_at && s.status === 'active' ? <Button size="sm" variant="ghost" onClick={() => unrecip.mutate({ u: s.uuid, id: r.id })}>Remove access</Button> : null}</div>
                                    ))}</div>
                                ) : null}
                                <div><div className="mb-1 font-medium">Recent activity</div>{detail.data.log.slice(0, 10).map((l, i) => <div key={i} className="text-neutral-600">{formatDate(l.at)} · <span className="font-mono">{l.action}</span>{l.ip ? ` · ${l.ip}` : ''}</div>)}</div>
                            </div>
                        ) : null}
                    </div>
                ))}
            </Card>
        </>
    );
}
