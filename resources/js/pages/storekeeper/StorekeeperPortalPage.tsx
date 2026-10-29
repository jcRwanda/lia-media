import { compressImages } from '@/lib/images';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router';
import { api, fieldErrors } from '@/lib/api';
import { buildMaterialRequestWhatsappLink, formatDate } from '@/lib/utils';
import { ProofUploader } from '@/components/ProofUploader';
import { Badge, Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';
import { useState } from 'react';
import { ClipboardCheck, MessageCircle, PackageCheck, ScanLine, X } from 'lucide-react';

type Tab = 'to_hand_out' | 'out_now' | 'due_overdue' | 'history';

const tabs: { key: Tab; label: string }[] = [
    { key: 'to_hand_out', label: 'To hand out today' },
    { key: 'out_now', label: 'Out now' },
    { key: 'due_overdue', label: 'Due / overdue returns' },
    { key: 'history', label: 'History' },
];

interface BookingItem {
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
    equipment_item: { id: number; name: string; serial_number?: string | null; condition?: string | null } | null;
}

interface Booking {
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
    items: BookingItem[];
}

interface BookingsResponse {
    tab: Tab;
    counts: Record<Tab, number>;
    bookings: Booking[];
}

interface ItemForm {
    id: number;
    qty: string;
    condition: string;
    notes: string;
    files: File[];
}

export default function StorekeeperPortalPage() {
    const qc = useQueryClient();
    const [tab, setTab] = useState<Tab>('to_hand_out');
    const [action, setAction] = useState<{ booking: Booking; type: 'issue' | 'return' } | null>(null);
    const [forms, setForms] = useState<Record<number, ItemForm>>({});
    const [error, setError] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['storekeeper-bookings', tab],
        queryFn: async () => api<BookingsResponse>(`/storekeeper/bookings?tab=${tab}`),
    });

    const openAction = (booking: Booking, type: 'issue' | 'return') => {
        const next: Record<number, ItemForm> = {};
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
            for (const [index, item] of items.entries()) {
                const form = forms[item.id];
                if (!form || Number(form.qty) <= 0) continue;
                formData.append(`items[${index}][id]`, String(item.id));
                if (action.type === 'issue') {
                    formData.append(`items[${index}][qty_issued]`, form.qty);
                    formData.append(`items[${index}][issue_condition]`, form.condition);
                } else {
                    formData.append(`items[${index}][qty_returned]`, form.qty);
                    formData.append(`items[${index}][return_condition]`, form.condition);
                }
                if (form.notes.trim()) formData.append(`items[${index}][note]`, form.notes.trim());
                (await compressImages(form.files)).forEach((file, fileIndex) => {
                    formData.append(`items[${index}][${action.type === 'issue' ? 'issue_proofs' : 'return_proofs'}][${fileIndex}]`, file);
                });
            }
            return api(`/material-requests/${action.booking.id}/${action.type}`, { method: 'POST', body: formData });
        },
        onSuccess: () => {
            setAction(null);
            setForms({});
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

    return (
        <div className="space-y-4">
            <PageHeader
                title="Storekeeper portal"
                description="Approved bookings, hand-outs and returns. Every hand-out and check-in needs photo proof."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button asChild size="sm"><Link to="/storekeeper/scan"><ScanLine className="h-4 w-4" />Scan QR</Link></Button>
                        <Button asChild variant="secondary" size="sm">
                            <Link to="/equipment-inventory">Manage inventory</Link>
                        </Button>
                    </div>
                }
            />

            <div className="flex flex-wrap gap-1 border-b border-neutral-200 pb-2">
                {tabs.map((t) => {
                    const active = tab === t.key;
                    const count = data?.counts?.[t.key] ?? 0;
                    return (
                        <button
                            key={t.key}
                            onClick={() => setTab(t.key)}
                            className={`rounded-full px-3 py-1 text-xs ${active ? 'bg-lia-700 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
                        >
                            {t.label} <span className={active ? 'text-white/75' : 'text-neutral-400'}>{count}</span>
                        </button>
                    );
                })}
            </div>

            {isLoading ? <p className="text-sm text-neutral-500">Loading bookings…</p> : null}
            {!isLoading && (data?.bookings?.length ?? 0) === 0 ? (
                <EmptyState title="Nothing here yet">Bookings will appear as soon as they are approved.</EmptyState>
            ) : null}

            <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                {(data?.bookings || []).map((booking) => {
                    const whatsapp = buildMaterialRequestWhatsappLink(booking);
                    const canHandOut = booking.status === 'approved';
                    const canCheckIn = booking.status === 'issued' || booking.status === 'returned';
                    return (
                        <Card key={booking.id}>
                            <CardHeader className="flex-row items-start justify-between gap-2">
                                <div>
                                    <CardTitle className="font-mono">{booking.reference_code}</CardTitle>
                                    <p className="mt-1 text-sm font-medium">{booking.full_name}</p>
                                    <p className="text-xs text-neutral-500">
                                        {booking.phone} · {booking.requester_type}
                                    </p>
                                </div>
                                <div className="flex flex-wrap justify-end gap-1">
                                    <Badge tone={booking.is_overdue ? 'red' : booking.status === 'approved' ? 'amber' : booking.status === 'closed' ? 'green' : 'blue'}>
                                        {booking.status}
                                        {booking.is_overdue ? ' · overdue' : ''}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="grid grid-cols-2 gap-2 text-xs text-neutral-600">
                                    <div>
                                        <div className="font-medium text-neutral-700">Pick-up</div>
                                        {booking.needed_from || '—'}
                                    </div>
                                    <div>
                                        <div className="font-medium text-neutral-700">Return by</div>
                                        {booking.return_by || '—'}
                                    </div>
                                    <div className="col-span-2">
                                        <div className="font-medium text-neutral-700">Pick-up location</div>
                                        {booking.pickup_location || '—'}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    {booking.items.map((item) => (
                                        <div key={item.id} className="rounded-lg border border-neutral-100 bg-neutral-50 p-2">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="font-medium">{item.equipment_item?.name || 'Item'}</div>
                                                    <div className="text-xs text-neutral-500">
                                                        Requested {item.qty_requested}
                                                        {item.qty_approved != null ? ` · Approved ${item.qty_approved}` : ''}
                                                        {item.qty_issued != null ? ` · Out ${item.qty_issued}` : ''}
                                                        {item.qty_returned != null ? ` · Returned ${item.qty_returned}` : ''}
                                                        {item.equipment_item?.serial_number ? ` · SN ${item.equipment_item.serial_number}` : ''}
                                                    </div>
                                                </div>
                                            </div>
                                            {(item.issue_proof_urls.length > 0 || item.return_proof_urls.length > 0) && (
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {[...item.issue_proof_urls, ...item.return_proof_urls].map((url) => (
                                                        <a key={url} href={url} target="_blank" rel="noreferrer">
                                                            <img src={url} alt="Proof" className="h-12 w-12 rounded border object-cover" />
                                                        </a>
                                                    ))}
                                                </div>
                                            )}
                                            <div className="mt-1 text-[11px] text-neutral-500">
                                                {item.issued_at ? `Handed out ${formatDate(item.issued_at)}${item.issued_by ? ` by ${item.issued_by}` : ''}` : ''}
                                                {item.returned_at ? ` · Checked in ${formatDate(item.returned_at)}${item.received_by ? ` by ${item.received_by}` : ''}` : ''}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {canHandOut ? (
                                        <Button size="sm" onClick={() => openAction(booking, 'issue')}>
                                            <PackageCheck className="h-4 w-4" /> Hand out
                                        </Button>
                                    ) : null}
                                    {canCheckIn ? (
                                        <Button size="sm" variant="secondary" onClick={() => openAction(booking, 'return')}>
                                            <ClipboardCheck className="h-4 w-4" /> Check in
                                        </Button>
                                    ) : null}
                                    {whatsapp ? (
                                        <Button asChild size="sm" variant="secondary">
                                            <a href={whatsapp} target="_blank" rel="noreferrer">
                                                <MessageCircle className="h-4 w-4" /> Send on WhatsApp
                                            </a>
                                        </Button>
                                    ) : null}
                                    <Link to={`/material-requests/${booking.id}`} className="ml-auto text-xs text-lia-700 underline">
                                        View booking
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {action ? (
                <div className="fixed inset-0 z-40 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" onClick={() => setAction(null)}>
                    <div
                        className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl sm:rounded-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="mb-3 flex items-start justify-between gap-2">
                            <div>
                                <h2 className="text-lg font-semibold">{action.type === 'issue' ? 'Hand out equipment' : 'Check in equipment'}</h2>
                                <p className="text-sm text-neutral-500">
                                    {action.booking.reference_code} · {action.booking.full_name}
                                </p>
                            </div>
                            <button aria-label="Close" onClick={() => setAction(null)} className="rounded p-1 hover:bg-neutral-100">
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        {error ? <div className="mb-3 rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-700">{error}</div> : null}

                        <div className="space-y-3">
                            {action.booking.items.map((item) => {
                                const form = forms[item.id];
                                if (!form) return null;
                                return (
                                    <div key={item.id} className="space-y-2 rounded-lg border border-neutral-200 p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <div>
                                                <div className="text-sm font-medium">{item.equipment_item?.name || 'Item'}</div>
                                                <div className="text-xs text-neutral-500">
                                                    {action.type === 'issue'
                                                        ? `Approved quantity: ${item.qty_approved ?? item.qty_requested}`
                                                        : `Outstanding: ${Math.max((item.qty_issued ?? item.qty_approved ?? item.qty_requested) - (item.qty_returned ?? 0), 0)}`}
                                                </div>
                                            </div>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={form.qty}
                                                onChange={(event) => setForms((prev) => ({ ...prev, [item.id]: { ...prev[item.id], qty: event.target.value } }))}
                                                className="w-24"
                                                aria-label="Quantity"
                                            />
                                        </div>
                                        <Input
                                            placeholder="Condition notes"
                                            value={form.condition}
                                            onChange={(event) => setForms((prev) => ({ ...prev, [item.id]: { ...prev[item.id], condition: event.target.value } }))}
                                        />
                                        <Textarea
                                            placeholder="Additional notes (optional)"
                                            value={form.notes}
                                            onChange={(event) => setForms((prev) => ({ ...prev, [item.id]: { ...prev[item.id], notes: event.target.value } }))}
                                            rows={2}
                                        />
                                        <ProofUploader
                                            label={action.type === 'issue' ? 'Hand-out photo proof' : 'Check-in photo proof'}
                                            files={form.files}
                                            onChange={(files) => setForms((prev) => ({ ...prev, [item.id]: { ...prev[item.id], files } }))}
                                        />
                                    </div>
                                );
                            })}
                        </div>

                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setAction(null)}>
                                Cancel
                            </Button>
                            <Button disabled={submit.isPending} onClick={submitWithValidation}>
                                {submit.isPending ? 'Saving…' : action.type === 'issue' ? 'Confirm hand-out' : 'Confirm check-in'}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
