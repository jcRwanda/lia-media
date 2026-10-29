import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, Navigate } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import { Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api, fieldErrors } from '@/lib/api';
import type { AssetCard } from '@/lib/types';
import { buildMaterialRequestWhatsappLink, formatDate } from '@/lib/utils';
import { formatBytes } from '@/lib/utils';
import { ProofUploader } from '@/components/ProofUploader';
import { Input } from '@/components/ui/input';
import { useState } from 'react';
import { ClipboardCheck, MessageCircle, PackageCheck, ScanLine } from 'lucide-react';

type Dash = {
    review_counts: Record<string, number>; consent_issues: number; uploads_last_7_days: number; open_uploads: number; active_shares: number;
    storage: { total_bytes: number; asset_count: number; estimated_monthly_usd: number; monthly_budget_usd: number }; recent: AssetCard[];
};

function Stat({ label, value, to, warn }: { label: string; value: React.ReactNode; to?: string; warn?: boolean }) {
    const inner = <Card className={warn ? 'border-lia-200 bg-lia-50/50' : ''}><CardContent className="p-4"><div className="text-xs text-neutral-500">{label}</div><div className={`mt-1 text-2xl font-semibold ${warn ? 'text-lia-700' : ''}`}>{value}</div></CardContent></Card>;
    return to ? <Link to={to} className="block hover:opacity-90">{inner}</Link> : inner;
}

type StoreBookingItem = {
    id: number;
    qty_requested: number;
    qty_approved: number | null;
    qty_issued: number | null;
    qty_returned: number | null;
    issue_condition: string | null;
    return_condition: string | null;
    issued_at: string | null;
    returned_at: string | null;
    issued_by: string | null;
    received_by: string | null;
    issue_proof_urls: string[];
    return_proof_urls: string[];
    equipment_item: { id: number; name: string; serial_number?: string | null; condition?: string | null; images?: { id: number; name: string; url: string }[] } | null;
};

type StoreBooking = {
    id: number;
    reference_code: string;
    full_name: string;
    phone: string;
    email: string;
    requester_type: string;
    status: string;
    needed_from: string | null;
    return_by: string | null;
    pickup_location: string | null;
    is_overdue: boolean;
    items: StoreBookingItem[];
    type?: string;
};

type StoreDashboardData = {
    stats: {
        to_hand_out_today: number;
        currently_out: number;
        due_today: number;
        overdue: number;
        returned_this_week: number;
        items_in_inventory: number;
        items_low_unavailable: number;
        damaged_needs_repair: number;
    };
    today: StoreBooking[];
    overdue: StoreBooking[];
    upcoming: StoreBooking[];
    recent_activity: Array<{
        id: number;
        material_request_id: number | null;
        reference_code: string | null;
        requester_name: string | null;
        equipment_name: string | null;
        type: string;
        issued_at: string | null;
        returned_at: string | null;
        qty_issued: number | null;
        qty_returned: number | null;
        proof_thumbnails: string[];
        issued_by: string | null;
        received_by: string | null;
    }>;
};

function StoreDashboard() {
    const qc = useQueryClient();
    const { can } = useAuth();
    const { data, isLoading } = useQuery({
        queryKey: ['store-dashboard'],
        queryFn: () => api<StoreDashboardData>('/store/dashboard'),
    });

    const [action, setAction] = useState<{ booking: StoreBooking; type: 'issue' | 'return' } | null>(null);
    const [forms, setForms] = useState<Record<number, { id: number; qty: string; condition: string; notes: string; files: File[] }>>({});
    const [error, setError] = useState('');

    const openAction = (booking: StoreBooking, type: 'issue' | 'return') => {
        const next: Record<number, any> = {};
        booking.items.forEach((item) => {
            const outstanding =
                type === 'issue'
                    ? (item.qty_approved ?? item.qty_requested) - (item.qty_issued ?? 0)
                    : (item.qty_issued ?? item.qty_approved ?? item.qty_requested) - (item.qty_returned ?? 0);
            next[item.id] = {
                id: item.id,
                qty: String(Math.max(outstanding, 0)),
                condition: type === 'issue' ? item.issue_condition || '' : item.return_condition || '',
                notes: '',
                files: [],
            };
        });
        setForms(next);
        setError('');
        setAction({ booking, type });
    };

    const submit = useMutation({
        mutationFn: async () => {
            if (!action) return;
            const formData = new FormData();
            const items = action.booking.items.filter((item) => forms[item.id]);
            items.forEach((item, index) => {
                const form = forms[item.id];
                if (!form || Number(form.qty) <= 0) return;
                formData.append(`items[${index}][id]`, String(item.id));
                if (action.type === 'issue') {
                    formData.append(`items[${index}][qty_issued]`, form.qty);
                    formData.append(`items[${index}][issue_condition]`, form.condition);
                } else {
                    formData.append(`items[${index}][qty_returned]`, form.qty);
                    formData.append(`items[${index}][return_condition]`, form.condition);
                }
                if (form.notes.trim()) formData.append(`items[${index}][note]`, form.notes.trim());
                form.files.forEach((file, fileIndex) => {
                    formData.append(`items[${index}][${action.type === 'issue' ? 'issue_proofs' : 'return_proofs'}][${fileIndex}]`, file);
                });
            });
            return api(`/material-requests/${action.booking.id}/${action.type}`, { method: 'POST', body: formData });
        },
        onSuccess: () => {
            setAction(null);
            setForms({});
            qc.invalidateQueries({ queryKey: ['store-dashboard'] });
            qc.invalidateQueries({ queryKey: ['storekeeper-bookings'] });
            qc.invalidateQueries({ queryKey: ['material-request'] });
            qc.invalidateQueries({ queryKey: ['material-requests'] });
        },
        onError: (e) => {
            const errors = fieldErrors(e);
            setError(Object.values(errors)[0] || (e instanceof Error ? e.message : 'Could not save'));
        },
    });

    const submitWithValidation = () => {
        if (!action) return;
        const formsToSubmit = action.booking.items
            .map((item) => forms[item.id])
            .filter((form) => form && Number(form.qty) > 0);
        if (formsToSubmit.length === 0) {
            setError('Enter a quantity for at least one item.');
            return;
        }
        if (formsToSubmit.some((form) => form!.files.length === 0)) {
            setError('Attach at least one photo proof for every item being processed.');
            return;
        }
        setError('');
        submit.mutate();
    };

    const s = data?.stats;

    return (
        <div className="space-y-5">
            <PageHeader title="Storekeeper Dashboard" description="Today's hand-outs, returns, and inventory overview" actions={<Button asChild size="sm"><Link to="/storekeeper/scan"><ScanLine className="h-4 w-4" />Scan QR</Link></Button>} />
            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            {s ? (
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                    <Stat label="To hand out today" value={s.to_hand_out_today} />
                    <Stat label="Currently out" value={s.currently_out} />
                    <Stat label="Due today" value={s.due_today} />
                    <Stat label="Overdue" value={s.overdue} warn={s.overdue > 0} />
                    <Stat label="Returned this week" value={s.returned_this_week} />
                    <Stat label="Items in inventory" value={s.items_in_inventory} />
                    <Stat label="Items low/unavailable" value={s.items_low_unavailable} warn={s.items_low_unavailable > 0} />
                    <Stat label="Damaged/needs repair" value={s.damaged_needs_repair} warn={s.damaged_needs_repair > 0} />
                </div>
            ) : null}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Today</CardTitle></CardHeader>
                    <CardContent className="space-y-3">
                        {(data?.today || []).length === 0 ? <p className="text-sm text-neutral-500">Nothing scheduled for today.</p> : null}
                        {(data?.today || []).map((booking) => {
                            const whatsapp = buildMaterialRequestWhatsappLink(booking as any);
                            const canHandOut = booking.status === 'approved';
                            const canCheckIn = booking.status === 'issued' || booking.status === 'returned';
                            return (
                                <div key={booking.id} className="rounded-lg border border-neutral-200 p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-sm">{booking.reference_code}</span>
                                                <span className="text-xs text-neutral-500">{booking.full_name}</span>
                                            </div>
                                            <div className="mt-1 text-xs text-neutral-500">{booking.phone} {booking.needed_from ? `· Needed ${formatDate(booking.needed_from)}` : ''} {booking.return_by ? `· Return ${formatDate(booking.return_by)}` : ''}</div>
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {whatsapp ? <Button asChild size="sm" variant="secondary">
                                                <a href={whatsapp} target="_blank" rel="noreferrer"><MessageCircle className="mr-1 h-3.5 w-3.5" />WhatsApp</a>
                                            </Button> : null}
                                            {canHandOut ? <Button size="sm" onClick={() => openAction(booking, 'issue')}><PackageCheck className="mr-1 h-3.5 w-3.5" />Hand out</Button> : null}
                                            {canCheckIn ? <Button size="sm" variant="secondary" onClick={() => openAction(booking, 'return')}><ClipboardCheck className="mr-1 h-3.5 w-3.5" />Check in</Button> : null}
                                        </div>
                                    </div>
                                    <div className="mt-2 space-y-1">
                                        {booking.items.map((item) => (
                                            <div key={item.id} className="text-xs text-neutral-600">{item.equipment_item?.name} · Requested {item.qty_requested}{item.qty_approved != null ? ` / Approved ${item.qty_approved}` : ''}{item.qty_issued != null ? ` / Issued ${item.qty_issued}` : ''}</div>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Overdue</CardTitle></CardHeader>
                    <CardContent className="space-y-3">
                        {(data?.overdue || []).length === 0 ? <p className="text-sm text-neutral-500">No overdue items.</p> : null}
                        {(data?.overdue || []).map((booking) => {
                            const whatsapp = buildMaterialRequestWhatsappLink(booking as any);
                            return (
                                <div key={booking.id} className="rounded-lg border border-red-200 bg-red-50/30 p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-sm">{booking.reference_code}</span>
                                                <span className="text-xs text-neutral-600">{booking.full_name}</span>
                                            </div>
                                            <div className="mt-1 text-xs text-neutral-600">{booking.phone} · Return by {booking.return_by ? formatDate(booking.return_by) : '-'}</div>
                                        </div>
                                        {whatsapp ? <Button asChild size="sm" variant="secondary">
                                            <a href={whatsapp} target="_blank" rel="noreferrer"><MessageCircle className="mr-1 h-3.5 w-3.5" />WhatsApp reminder</a>
                                        </Button> : null}
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Next 7 days (upcoming bookings)</CardTitle></CardHeader>
                    <CardContent className="space-y-2">
                        {(data?.upcoming || []).length === 0 ? <p className="text-sm text-neutral-500">Nothing upcoming in the next 7 days.</p> : null}
                        {(data?.upcoming || []).map((booking) => (
                            <div key={booking.id} className="flex items-center justify-between rounded-lg border border-neutral-200 p-2 text-sm">
                                <div>
                                    <span className="font-mono">{booking.reference_code}</span> · {booking.full_name}
                                    <div className="text-xs text-neutral-500">{booking.needed_from ? formatDate(booking.needed_from) : '-'} {booking.return_by ? `→ ${formatDate(booking.return_by)}` : ''}</div>
                                </div>
                                <Button asChild size="sm" variant="secondary"><Link to={`/material-requests/${booking.id}`}>View</Link></Button>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Recent activity (last hand-outs/returns)</CardTitle></CardHeader>
                    <CardContent className="space-y-2">
                        {(data?.recent_activity || []).length === 0 ? <p className="text-sm text-neutral-500">No recent activity.</p> : null}
                        {(data?.recent_activity || []).map((act) => (
                            <div key={act.id} className="flex items-start gap-3 rounded-lg border border-neutral-200 p-2">
                                <div className="flex flex-1 flex-col">
                                    <div className="flex items-center justify-between text-sm">
                                        <div>
                                            <span className="font-mono">{act.reference_code}</span> · {act.requester_name}
                                        </div>
                                        <span className="text-xs text-neutral-500">{act.type === 'return' ? (act.returned_at ? formatDate(act.returned_at) : '') : (act.issued_at ? formatDate(act.issued_at) : '')}</span>
                                    </div>
                                    <div className="text-xs text-neutral-500">{act.equipment_name} · {act.type === 'return' ? `Returned ${act.qty_returned ?? '-'}` : `Issued ${act.qty_issued ?? '-'}`}</div>
                                    {act.proof_thumbnails?.length ? (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {act.proof_thumbnails.map((url, idx) => (
                                                <a key={idx} href={url} target="_blank" rel="noreferrer" className="block h-12 w-12 overflow-hidden rounded border border-neutral-200">
                                                    <img src={url} alt="proof" className="h-full w-full object-cover" />
                                                </a>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            {action ? (
                <div className="fixed inset-0 z-40 flex items-end justify-center bg-black/40 p-4 sm:items-center">
                    <div className="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                        <div className="flex items-center justify-between border-b border-neutral-200 px-4 py-3">
                            <div>
                                <div className="font-medium">{action.type === 'issue' ? 'Hand out' : 'Check in'} · {action.booking.reference_code}</div>
                                <div className="text-xs text-neutral-500">{action.booking.full_name}</div>
                            </div>
                            <button onClick={() => setAction(null)} className="text-neutral-500">×</button>
                        </div>
                        <div className="max-h-[70vh] space-y-3 overflow-auto px-4 py-3">
                            {action.booking.items.map((item) => {
                                const form = forms[item.id];
                                if (!form) return null;
                                const outstanding =
                                    action.type === 'issue'
                                        ? (item.qty_approved ?? item.qty_requested) - (item.qty_issued ?? 0)
                                        : (item.qty_issued ?? item.qty_approved ?? item.qty_requested) - (item.qty_returned ?? 0);
                                return (
                                    <div key={item.id} className="rounded-lg border border-neutral-200 p-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <div className="text-sm font-medium">{item.equipment_item?.name}</div>
                                                <div className="text-xs text-neutral-500">Outstanding {Math.max(outstanding, 0)}</div>
                                            </div>
                                        </div>
                                        <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            <div>
                                                <label className="text-xs text-neutral-600">Quantity</label>
                                                <Input value={form.qty} onChange={(e) => setForms((f) => ({ ...f, [item.id]: { ...f[item.id], qty: e.target.value } }))} />
                                            </div>
                                            <div>
                                                <label className="text-xs text-neutral-600">{action.type === 'issue' ? 'Issue condition' : 'Return condition'}</label>
                                                <Input value={form.condition} onChange={(e) => setForms((f) => ({ ...f, [item.id]: { ...f[item.id], condition: e.target.value } }))} />
                                            </div>
                                        </div>
                                        <div className="mt-2">
                                            <label className="text-xs text-neutral-600">Photo proof (required)</label>
                                            <ProofUploader files={form.files} onChange={(files) => setForms((f) => ({ ...f, [item.id]: { ...f[item.id], files } }))} />
                                        </div>
                                    </div>
                                );
                            })}
                            {error ? <p className="text-sm text-red-600">{error}</p> : null}
                        </div>
                        <div className="flex items-center justify-end gap-2 border-t border-neutral-200 px-4 py-3">
                            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
                            <Button onClick={submitWithValidation} disabled={submit.isPending}>{submit.isPending ? 'Saving…' : 'Save'}</Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

export default function DashboardPage() {
    const { user, can } = useAuth();
    const q = useQuery({ queryKey: ['dashboard'], queryFn: () => api<Dash>('/dashboard'), enabled: can('dashboard.view') });
    const d = q.data;

    const isStoreUser = (can('material-requests.issue') || can('equipment.manage')) && !can('dashboard.view');
    if (user && isStoreUser) {
        return <StoreDashboard />;
    }

    if (user && !can('dashboard.view')) {
        const home = [
            ['material-requests.issue', '/storekeeper'],
            ['material-requests.view', '/material-requests'],
            ['equipment.manage', '/equipment-inventory'],
            ['library.view', '/library'],
            ['users.manage', '/admin/users'],
        ].find(([a]) => can(a));
        return <Navigate to={home ? home[1] : '/profile'} replace />;
    }
    return (
        <>
            <PageHeader title={`Welcome, ${user?.name}`} description="Love In Arts media archive" />
            {d ? (
                <div className="space-y-5">
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Awaiting review" value={d.review_counts.unreviewed} to={can('review.approve') ? '/review' : undefined} warn={d.review_counts.unreviewed > 0} />
                        <Stat label="Approved / published" value={`${d.review_counts.approved} / ${d.review_counts.published}`} />
                        <Stat label="Consent issues (shareable items)" value={d.consent_issues} warn={d.consent_issues > 0} />
                        <Stat label="Uploads, last 7 days" value={d.uploads_last_7_days} />
                        <Stat label="Items in archive" value={d.storage.asset_count} to="/library" />
                        <Stat label="Storage used" value={formatBytes(d.storage.total_bytes)} to="/storage" />
                        <Stat label="Est. storage cost / month" value={`$${d.storage.estimated_monthly_usd.toFixed(2)}`} warn={d.storage.estimated_monthly_usd > d.storage.monthly_budget_usd} to="/storage" />
                        <Stat label="Active share links" value={d.active_shares} to={can('shares.manage') ? '/shares' : undefined} />
                    </div>
                    <Card>
                        <CardHeader><CardTitle>Recently added</CardTitle></CardHeader>
                        <CardContent>{d.recent.length ? <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">{d.recent.map((a) => <AssetTile key={a.uuid} asset={a} />)}</div> : <p className="text-sm text-neutral-500">Nothing uploaded yet.</p>}</CardContent>
                    </Card>
                </div>
            ) : q.isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
        </>
    );
}
