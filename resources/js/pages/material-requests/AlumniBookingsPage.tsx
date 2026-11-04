import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { useState } from 'react';
import { CalendarDays, GraduationCap, Package, Search } from 'lucide-react';
import { api } from '@/lib/api';

const STATUSES: { key: string; label: string; tone: string }[] = [
    { key: 'submitted', label: 'Submitted', tone: 'bg-amber-100 text-amber-800' },
    { key: 'changes_requested', label: 'Changes requested', tone: 'bg-orange-100 text-orange-800' },
    { key: 'approved', label: 'Approved', tone: 'bg-emerald-100 text-emerald-800' },
    { key: 'issued', label: 'Issued', tone: 'bg-sky-100 text-sky-800' },
    { key: 'returned', label: 'Returned', tone: 'bg-indigo-100 text-indigo-800' },
    { key: 'rejected', label: 'Rejected', tone: 'bg-rose-100 text-rose-800' },
    { key: 'closed', label: 'Closed', tone: 'bg-neutral-200 text-neutral-700' },
];

export function statusTone(status: string): string {
    return STATUSES.find((s) => s.key === status)?.tone ?? 'bg-neutral-100 text-neutral-700';
}

export default function AlumniBookingsPage() {
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ['alumni-bookings', status, search, page],
        queryFn: () => {
            const q = new URLSearchParams({ requester_type: 'alumni', page: String(page), per_page: '20' });
            if (status) q.set('status', status);
            if (search.trim()) q.set('search', search.trim());
            return api<any>('/material-requests?' + q.toString());
        },
    });

    const rows: any[] = data?.data ?? [];
    const counts: Record<string, number> = data?.status_counts ?? {};
    const all = Object.values(counts).reduce((a, b) => a + Number(b), 0);

    return (
        <div className="space-y-5 p-4">
            <div className="rounded-2xl bg-gradient-to-r from-lia-700 to-lia-500 p-5 text-white shadow-sm">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl bg-white/15 p-2"><GraduationCap className="h-6 w-6" /></div>
                    <div>
                        <h1 className="text-xl font-semibold">Alumni bookings</h1>
                        <p className="text-sm text-white/80">Equipment requests from LIA alumni, with every detail of the alumni form.</p>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <button onClick={() => { setStatus(''); setPage(1); }}
                    className={`rounded-full px-3 py-1.5 text-xs font-medium ${status === '' ? 'bg-lia-700 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'}`}>
                    All <span className="opacity-70">{all}</span>
                </button>
                {STATUSES.map((s) => (
                    <button key={s.key} onClick={() => { setStatus(s.key); setPage(1); }}
                        className={`rounded-full px-3 py-1.5 text-xs font-medium ${status === s.key ? 'bg-lia-700 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'}`}>
                        {s.label} <span className="opacity-70">{counts[s.key] ?? 0}</span>
                    </button>
                ))}
                <label className="ml-auto flex min-w-[14rem] items-center gap-2 rounded-lg border border-neutral-200 bg-white px-3 py-1.5 text-sm">
                    <Search className="h-4 w-4 text-neutral-400" />
                    <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        placeholder="Name, email, alumni ID, project…" className="w-full outline-none" />
                </label>
            </div>

            {isLoading ? (
                <div className="text-sm text-neutral-500">Loading…</div>
            ) : rows.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-sm text-neutral-500">
                    No alumni bookings {status || search ? 'match these filters' : 'yet'}.
                </div>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {rows.map((r) => (
                        <Link key={r.id} to={`/material-requests/${r.id}`}
                            className="group rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm transition hover:border-lia-500 hover:shadow-md">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-semibold text-neutral-900 group-hover:text-lia-700">{r.full_name}</div>
                                    <div className="text-xs text-neutral-500">{r.email} · {r.phone}</div>
                                </div>
                                <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium ${statusTone(r.status)}`}>
                                    {String(r.status).replace('_', ' ')}
                                </span>
                            </div>
                            <div className="mt-3 text-sm font-medium text-neutral-800">{r.project_title || r.project_name || 'Untitled project'}</div>
                            {r.project_type && <div className="text-xs text-neutral-500">{r.project_type}</div>}
                            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
                                <span className="inline-flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" />{r.needed_from} → {r.return_by}</span>
                                <span className="inline-flex items-center gap-1"><Package className="h-3.5 w-3.5" />{r.items?.length ?? 0} item(s)</span>
                                {(r.program_cohort || r.graduation_completion_year) && (
                                    <span className="inline-flex items-center gap-1"><GraduationCap className="h-3.5 w-3.5" />{[r.program_cohort, r.graduation_completion_year].filter(Boolean).join(' · ')}</span>
                                )}
                            </div>
                            <div className="mt-3 flex items-center justify-between border-t border-neutral-100 pt-2 text-[11px] text-neutral-400">
                                <span className="font-mono">{r.reference_code}</span>
                                <span>{r.source || 'Direct/Hosted form'} · {r.created_at?.slice(0, 10)}</span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}

            {data?.last_page > 1 && (
                <div className="flex items-center justify-center gap-2 text-sm">
                    <button disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded border px-3 py-1 disabled:opacity-40">Previous</button>
                    <span>Page {page} of {data.last_page}</span>
                    <button disabled={page >= data.last_page} onClick={() => setPage(page + 1)} className="rounded border px-3 py-1 disabled:opacity-40">Next</button>
                </div>
            )}
        </div>
    );
}
