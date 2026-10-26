import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { api } from '@/lib/api';
import { useState } from 'react';

function buildQuery(params: Record<string, any>) {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k,v]) => {
        if (v === undefined || v === null || v === '') return;
        q.set(k, String(v));
    });
    const s = q.toString();
    return s ? '?' + s : '';
}

export default function MaterialRequestsPage() {
    const [source, setSource] = useState('');
    const [status, setStatus] = useState('');
    const [requesterType, setRequesterType] = useState('');
    const [overdue, setOverdue] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['material-requests', source, status, requesterType, overdue],
        queryFn: async () => {
            const res = await api<any>('/material-requests' + buildQuery({
                source: source || undefined,
                status: status || undefined,
                requester_type: requesterType || undefined,
                overdue: overdue ? 1 : undefined,
            }));
            return res;
        },
    });

    const requests = data?.data || data || [];
    const sources = data?.sources || [];

    return (
        <div className="p-4 space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <h1 className="text-xl font-semibold">Material / Equipment Requests</h1>
                <div className="flex flex-wrap gap-2">
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="border rounded px-2 py-1 text-sm">
                        <option value="">All statuses</option>
                        <option value="submitted">Submitted</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="issued">Issued</option>
                        <option value="returned">Returned</option>
                        <option value="closed">Closed</option>
                    </select>
                    <select value={requesterType} onChange={(e) => setRequesterType(e.target.value)} className="border rounded px-2 py-1 text-sm">
                        <option value="">All types</option>
                        <option value="client">Client</option>
                        <option value="trainee">Trainee</option>
                        <option value="alumni">Alumni</option>
                    </select>
                    <label className="flex items-center gap-1 text-sm"><input type="checkbox" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} /> Overdue</label>
                </div>
            </div>

            {sources.length > 0 && (
                <div className="flex flex-wrap gap-1 border-b border-neutral-200 pb-2">
                    {sources.map((s: any) => {
                        const active = s.key === 'all' ? source === '' : source === s.key;
                        return (
                            <button
                                key={s.key}
                                onClick={() => setSource(s.key === 'all' ? '' : s.key)}
                                className={`rounded-full px-3 py-1 text-xs ${active ? 'bg-lia-700 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
                            >
                                {s.label} <span className={active ? 'text-white/75' : 'text-neutral-400'}>{s.count}</span>
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="overflow-x-auto border rounded">
                <table className="w-full text-sm">
                    <thead className="bg-neutral-50">
                        <tr>
                            <th className="text-left p-2">Reference</th>
                            <th className="text-left p-2">Requester</th>
                            <th className="text-left p-2">Type</th>
                            <th className="text-left p-2">Source</th>
                            <th className="text-left p-2">Status</th>
                            <th className="text-left p-2">Return by</th>
                            <th className="text-left p-2">Created</th>
                            <th className="text-left p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading ? (
                            <tr><td className="p-2" colSpan={8}>Loading...</td></tr>
                        ) : requests.length === 0 ? (
                            <tr><td className="p-2" colSpan={8}>No requests</td></tr>
                        ) : requests.map((r: any) => (
                            <tr key={r.id} className="border-t">
                                <td className="p-2 font-mono text-xs">{r.reference_code}</td>
                                <td className="p-2">{r.full_name}<br /><span className="text-xs text-neutral-500">{r.email}</span></td>
                                <td className="p-2 capitalize">{r.requester_type}</td>
                                <td className="p-2">
                                    <div className="text-xs font-medium">{r.source || 'Direct/Hosted form'}</div>
                                    {r.source_detail && <div className="text-[11px] text-neutral-500">{r.source_detail}</div>}
                                </td>
                                <td className="p-2 capitalize">{r.status}</td>
                                <td className="p-2">{r.return_by}</td>
                                <td className="p-2">{r.created_at?.slice(0,10)}</td>
                                <td className="p-2"><Link className="text-blue-600" to={`/material-requests/${r.id}`}>View</Link></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
