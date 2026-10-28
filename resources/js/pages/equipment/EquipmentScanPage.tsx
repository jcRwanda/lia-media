import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router';
import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { AlertTriangle, CheckCircle2, ScanLine } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { Badge, Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';

interface Check {
    id: number;
    type: string;
    actor: string | null;
    borrower_name: string | null;
    serial_matches: boolean | null;
    notes: string | null;
    flagged: boolean;
    flag_reason: string | null;
    created_at: string | null;
}

interface ScanRes {
    item: {
        id: number;
        name: string;
        category: string | null;
        serial_number: string | null;
        asset_tag: string | null;
        condition: string | null;
        photo_url: string | null;
        images: { id: number; url: string | null }[];
    };
    bookings: {
        id: number;
        reference_code: string;
        full_name: string;
        phone: string;
        status: string;
        needed_from: string | null;
        return_by: string | null;
        pickup_confirmed: boolean;
        item_line: { issue_condition: string | null } | null;
    }[];
    recent_checks: Check[];
}

export const checkLabel = (t: string) => (t === 'pickup_confirm' ? 'Borrower confirmed pickup' : t === 'issue_check' ? 'Hand-out check' : 'Return check');

function extractToken(text: string): string | null {
    const m = text.match(/\/q\/([A-Za-z0-9]{20,40})/) || text.trim().match(/^([A-Za-z0-9]{20,40})$/);
    return m ? m[1] : null;
}

function Scanner({ onToken }: { onToken: (t: string) => void }) {
    const ref = useRef<Html5Qrcode | null>(null);
    const [err, setErr] = useState('');
    useEffect(() => {
        const scanner = new Html5Qrcode('qr-reader');
        ref.current = scanner;
        let done = false;
        scanner
            .start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, (text) => {
                const t = extractToken(text);
                if (t && !done) {
                    done = true;
                    onToken(t);
                }
            }, () => undefined)
            .catch(() => setErr('Camera not available. Allow camera access, or paste the code below.'));
        return () => {
            if (scanner.isScanning) scanner.stop().catch(() => undefined);
        };
    }, [onToken]);
    return (
        <div className="space-y-2">
            <div id="qr-reader" className="mx-auto w-full max-w-sm overflow-hidden rounded-lg bg-black" />
            {err ? <p className="text-xs text-red-600">{err}</p> : null}
        </div>
    );
}

export default function EquipmentScanPage() {
    const qc = useQueryClient();
    const [token, setToken] = useState<string | null>(null);
    const [scanning, setScanning] = useState(true);
    const [manual, setManual] = useState('');
    const [bookingId, setBookingId] = useState<number | ''>('');
    const [type, setType] = useState<'issue_check' | 'return_check'>('issue_check');
    const [serialMatches, setSerialMatches] = useState<boolean | null>(null);
    const [notes, setNotes] = useState('');

    const { data, error, isLoading } = useQuery({
        queryKey: ['equipment-scan', token],
        queryFn: () => api<ScanRes>(`/equipment-scan/${token}`),
        enabled: !!token,
        retry: false,
    });

    useEffect(() => {
        if (data?.bookings.length) {
            const b = data.bookings[0];
            setBookingId(b.id);
            setType(b.status === 'issued' ? 'return_check' : 'issue_check');
        } else setBookingId('');
        setSerialMatches(null);
        setNotes('');
    }, [data]);

    const save = useMutation({
        mutationFn: () =>
            api<{ check: Check }>(`/equipment-scan/${token}/check`, {
                method: 'POST',
                json: { type, material_request_id: bookingId || null, serial_matches: serialMatches, notes: notes || null },
            }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['equipment-scan', token] }),
    });

    const onToken = (t: string) => {
        setToken(t);
        setScanning(false);
        save.reset();
    };

    const item = data?.item;
    const booking = data?.bookings.find((b) => b.id === bookingId);

    return (
        <div className="space-y-4">
            <PageHeader title="Scan to check" description="Scan the QR label on a camera or other item to compare it with the stored record." />
            <Card>
                <CardContent className="space-y-3 pt-4">
                    {scanning ? (
                        <>
                            <Scanner onToken={onToken} />
                            <form
                                className="flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    const t = extractToken(manual);
                                    if (t) onToken(t);
                                }}
                            >
                                <Input value={manual} onChange={(e) => setManual(e.target.value)} placeholder="Or paste QR link / code" />
                                <Button type="submit" variant="secondary">Look up</Button>
                            </form>
                        </>
                    ) : (
                        <Button variant="secondary" onClick={() => setScanning(true)}>
                            <ScanLine className="mr-2 h-4 w-4" /> Scan another item
                        </Button>
                    )}
                </CardContent>
            </Card>

            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            {error ? <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error instanceof ApiError && error.status === 404 ? 'This QR code does not match any item.' : 'Could not load item.'}</p> : null}

            {item ? (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>Stored record</CardTitle></CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="text-base font-semibold">{item.name}</div>
                            <div className="rounded bg-neutral-100 p-2 font-mono text-base">SN: {item.serial_number || '— none stored —'}</div>
                            <div>Category: {item.category || '—'} · Tag: {item.asset_tag || '—'}</div>
                            <div>Condition on file: {item.condition || '—'}</div>
                            <div className="flex flex-wrap gap-2">
                                {(item.images.length ? item.images.map((i) => i.url) : [item.photo_url]).filter(Boolean).map((u) => (
                                    <a key={u!} href={u!} target="_blank" rel="noreferrer"><img src={u!} alt="" className="h-20 w-20 rounded border object-cover" /></a>
                                ))}
                            </div>
                            <div className="pt-2 font-medium">Current bookings</div>
                            {data!.bookings.length === 0 ? (
                                <div className="flex items-center gap-2 rounded border border-amber-200 bg-amber-50 p-2 text-amber-800"><AlertTriangle className="h-4 w-4" /> Not on any current booking</div>
                            ) : (
                                data!.bookings.map((b) => (
                                    <div key={b.id} className="rounded border p-2">
                                        <Link className="font-medium text-lia-700 underline" to={`/material-requests/${b.id}`}>{b.reference_code}</Link> · {b.full_name} · <Badge>{b.status}</Badge>
                                        <div className="text-xs text-neutral-500">{b.needed_from} → {b.return_by} · {b.phone}</div>
                                        <div className="text-xs">{b.pickup_confirmed ? '✅ Borrower confirmed pickup' : 'Borrower has not confirmed pickup yet'}</div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Checklist</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex gap-2">
                                <Button size="sm" variant={type === 'issue_check' ? 'default' : 'secondary'} onClick={() => setType('issue_check')}>Handing out</Button>
                                <Button size="sm" variant={type === 'return_check' ? 'default' : 'secondary'} onClick={() => setType('return_check')}>Receiving back</Button>
                            </div>
                            <label className="block">
                                Booking
                                <select className="mt-1 block w-full rounded border p-2" value={bookingId} onChange={(e) => setBookingId(e.target.value ? Number(e.target.value) : '')}>
                                    <option value="">No booking (will be flagged)</option>
                                    {data!.bookings.map((b) => <option key={b.id} value={b.id}>{b.reference_code} · {b.full_name}</option>)}
                                </select>
                            </label>
                            <div>
                                <div className="mb-1">Serial number on the item matches <b>{item.serial_number || '—'}</b>?</div>
                                <div className="flex gap-2">
                                    <Button size="sm" variant={serialMatches === true ? 'default' : 'secondary'} onClick={() => setSerialMatches(true)}>Yes, matches</Button>
                                    <Button size="sm" variant={serialMatches === false ? 'destructive' : 'secondary'} onClick={() => setSerialMatches(false)}>No, mismatch</Button>
                                </div>
                            </div>
                            <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Condition notes (scratches, missing parts, accessories…)" />
                            {serialMatches === false || !bookingId ? (
                                <div className="flex items-center gap-2 rounded border border-amber-200 bg-amber-50 p-2 text-amber-800"><AlertTriangle className="h-4 w-4" /> This check will be flagged.</div>
                            ) : null}
                            <Button disabled={serialMatches === null || save.isPending} onClick={() => save.mutate()}>
                                {save.isPending ? 'Saving…' : 'Record check'}
                            </Button>
                            {save.data ? (
                                <div className={`flex items-center gap-2 rounded border p-2 ${save.data.check.flagged ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800'}`}>
                                    {save.data.check.flagged ? <AlertTriangle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                                    {save.data.check.flagged ? `Recorded with flag: ${save.data.check.flag_reason}` : 'Check recorded.'}
                                </div>
                            ) : null}
                            {save.error ? <p className="text-xs text-red-600">{save.error instanceof ApiError ? save.error.message : 'Could not save'}</p> : null}
                            {booking ? (
                                <p className="text-xs text-neutral-500">
                                    To add {type === 'return_check' ? 'return' : 'hand-out'} proof photos and complete the {type === 'return_check' ? 'check-in' : 'hand-out'}, open{' '}
                                    <Link className="text-lia-700 underline" to={`/material-requests/${booking.id}`}>{booking.reference_code}</Link>.
                                </p>
                            ) : null}
                            <div className="pt-2 font-medium">Recent checks for this item</div>
                            {data!.recent_checks.length === 0 ? <p className="text-xs text-neutral-400">None yet.</p> : null}
                            {data!.recent_checks.map((c) => (
                                <div key={c.id} className="text-xs">
                                    {c.flagged ? '⚠️' : '✓'} {checkLabel(c.type)} · {c.actor || c.borrower_name || '—'} · {c.created_at ? new Date(c.created_at).toLocaleString() : ''}
                                    {c.flag_reason ? ` · ${c.flag_reason}` : ''}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            ) : null}
        </div>
    );
}
