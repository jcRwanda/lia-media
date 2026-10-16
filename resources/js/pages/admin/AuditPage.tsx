import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, PageHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import { formatDate } from '@/lib/utils';

interface Log { id: number; action: string; actor: string | null; actor_type: string; subject_type: string | null; subject_id: number | null; ip: string | null; properties: unknown; created_at: string }

export default function AuditPage() {
    const [action, setAction] = useState('');
    const [page, setPage] = useState(1);
    const q = useQuery({ queryKey: ['audit', action, page], queryFn: () => api<Paginated<Log>>(`/audit-logs?page=${page}&action=${encodeURIComponent(action)}`) });
    return (
        <>
            <PageHeader title="Audit log" description="Every sign-in, upload, review, share and download." actions={<Input className="w-56" placeholder="Filter action, e.g. share." value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} />} />
            <Card className="divide-y divide-neutral-100 text-sm">
                {(q.data?.data ?? []).map((l) => (
                    <div key={l.id} className="grid gap-1 p-3 sm:grid-cols-[170px_1fr_auto]">
                        <span className="text-xs text-neutral-500">{formatDate(l.created_at)}</span>
                        <span><span className="font-mono text-xs text-lia-700">{l.action}</span> · {l.actor ?? l.actor_type}{l.subject_type ? ` → ${l.subject_type} #${l.subject_id}` : ''}</span>
                        <span className="text-xs text-neutral-400">{l.ip}</span>
                    </div>
                ))}
            </Card>
            <div className="mt-3 flex gap-2">
                <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                <Button size="sm" variant="secondary" disabled={!q.data || page >= q.data.last_page} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
        </>
    );
}
