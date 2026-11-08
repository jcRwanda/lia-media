import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { ArrowLeftRight, PackagePlus, ScanLine, Search, Trash2, X } from 'lucide-react';
import { api, fieldErrors } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export interface PickupLine {
    id: number;
    qty_requested: number;
    qty_approved: number | null;
    qty_issued: number | null;
    status?: string;
    added_at_pickup?: boolean;
    added_by?: string | null;
    equipment_item?: {
        id: number;
        name: string;
        serial_number?: string | null;
        images?: { id: number; url?: string | null }[];
        accessories?: { id: number; name: string }[];
    } | null;
}

interface CatalogItem {
    id: number;
    name: string;
    category: string | null;
    serial_number: string | null;
    condition: string | null;
    available_qty: number;
    already_on_request: boolean;
    unavailable: boolean;
    unavailable_reason: string | null;
    photo_url: string | null;
    images: { id: number; url: string | null }[];
    accessories: { id: number; name: string }[];
}

function extractToken(text: string): string | null {
    const m = text.match(/\/q\/([A-Za-z0-9]{20,40})/) || text.trim().match(/^([A-Za-z0-9]{20,40})$/);
    return m ? m[1] : null;
}

function MiniScanner({ onToken, onClose }: { onToken: (t: string) => void; onClose: () => void }) {
    const hostId = useId().replace(/:/g, '');
    const ref = useRef<Html5Qrcode | null>(null);
    const [err, setErr] = useState('');
    const [manual, setManual] = useState('');

    useEffect(() => {
        const scanner = new Html5Qrcode(hostId);
        ref.current = scanner;
        let done = false;
        scanner
            .start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, (text) => {
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
    }, [hostId, onToken]);

    return (
        <div className="space-y-2 rounded-xl border border-lia-100 bg-lia-50/40 p-3">
            <div className="flex items-center justify-between gap-2">
                <div className="text-sm font-medium text-lia-800">Scan equipment QR</div>
                <button type="button" aria-label="Close scanner" onClick={onClose} className="rounded p-1 hover:bg-white">
                    <X className="h-4 w-4" />
                </button>
            </div>
            <div id={hostId} className="mx-auto w-full max-w-sm overflow-hidden rounded-lg bg-black" />
            {err ? <p className="text-xs text-red-600">{err}</p> : null}
            <div className="flex gap-2">
                <Input
                    value={manual}
                    onChange={(e) => setManual(e.target.value)}
                    placeholder="Or paste QR /q/… token"
                    className="text-sm"
                />
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                        const t = extractToken(manual);
                        if (t) onToken(t);
                        else setErr('That does not look like a valid equipment QR.');
                    }}
                >
                    Use
                </Button>
            </div>
        </div>
    );
}

export function AddAtPickupPanel({
    requestId,
    items,
    onChanged,
}: {
    requestId: number;
    items: PickupLine[];
    onChanged?: () => void;
}) {
    const qc = useQueryClient();
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    const [qtyById, setQtyById] = useState<Record<number, string>>({});
    const [error, setError] = useState('');
    const [scanning, setScanning] = useState(false);
    const [replaceScanning, setReplaceScanning] = useState(false);
    const [replacingId, setReplacingId] = useState<number | null>(null);
    const [candidate, setCandidate] = useState<CatalogItem | null>(null);
    const [selectedAccessories, setSelectedAccessories] = useState<{ name: string; accessories: { id: number; name: string }[] } | null>(null);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(q.trim()), 250);
        return () => clearTimeout(t);
    }, [q]);

    const catalog = useQuery({
        queryKey: ['pickup-catalog', requestId, debounced],
        queryFn: () => api<{ items: CatalogItem[] }>(`/material-requests/${requestId}/pickup-catalog?q=${encodeURIComponent(debounced)}`),
    });

    const invalidate = () => {
        qc.invalidateQueries({ queryKey: ['storekeeper-bookings'] });
        qc.invalidateQueries({ queryKey: ['material-request', String(requestId)] });
        qc.invalidateQueries({ queryKey: ['material-request', requestId] });
        qc.invalidateQueries({ queryKey: ['pickup-catalog', requestId] });
        onChanged?.();
    };

    const add = useMutation({
        mutationFn: async (payload: { equipment_item_id?: number; qr_token?: string; qty: number }) =>
            api(`/material-requests/${requestId}/items`, { method: 'POST', json: payload }),
        onSuccess: (_res, vars) => {
            setError('');
            setScanning(false);
            if (vars.equipment_item_id) {
                const item = catalog.data?.items.find((i) => i.id === vars.equipment_item_id);
                if (item?.accessories?.length) {
                    setSelectedAccessories({ name: item.name, accessories: item.accessories });
                }
            }
            invalidate();
        },
        onError: (e) => {
            const errors = fieldErrors(e);
            setError(Object.values(errors)[0] || (e instanceof Error ? e.message : 'Could not add item'));
        },
    });

    const remove = useMutation({
        mutationFn: async (itemId: number) => api(`/material-requests/${requestId}/items/${itemId}`, { method: 'DELETE' }),
        onSuccess: () => {
            setError('');
            invalidate();
        },
        onError: (e) => {
            const errors = fieldErrors(e);
            setError(Object.values(errors)[0] || (e instanceof Error ? e.message : 'Could not remove item'));
        },
    });

    const onToken = useCallback(
        (token: string) => {
            setError('');
            add.mutate({ qr_token: token, qty: 1 });
        },
        [add],
    );

    const replace = useMutation({
        mutationFn: async (payload: { lineId: number; equipmentItemId: number }) =>
            api(`/material-requests/${requestId}/items/${payload.lineId}/replace`, {
                method: 'POST',
                json: { equipment_item_id: payload.equipmentItemId },
            }),
        onSuccess: () => {
            setError('');
            setCandidate(null);
            setReplacingId(null);
            setReplaceScanning(false);
            invalidate();
        },
        onError: (e) => {
            const errors = fieldErrors(e);
            setError(Object.values(errors)[0] || (e instanceof Error ? e.message : 'Could not replace item'));
        },
    });

    const onReplaceToken = useCallback(async (token: string) => {
        setError('');
        try {
            const res = await api<{
                item: { id: number; name: string; serial_number: string | null; category: string | null; condition: string | null; photo_url: string | null; images: { id: number; url: string | null }[] };
            }>(`/equipment-scan/${token}`);
            const scanned = res.item;
            setCandidate({
                id: scanned.id,
                name: scanned.name,
                category: scanned.category,
                serial_number: scanned.serial_number,
                condition: scanned.condition,
                available_qty: 1,
                already_on_request: false,
                unavailable: false,
                unavailable_reason: null,
                photo_url: scanned.photo_url,
                images: scanned.images || [],
                accessories: [],
            });
            setReplaceScanning(false);
        } catch (e) {
            const errors = fieldErrors(e);
            setError(Object.values(errors)[0] || (e instanceof Error ? e.message : 'That QR is not an in-stock unit.'));
        }
    }, []);

    const pickupAdds = items.filter((i) => i.added_at_pickup && !i.qty_issued);
    const replaceable = items.filter((i) => !i.qty_issued && i.status !== 'issued');
    const replacing = replaceable.find((line) => line.id === replacingId) || null;

    return (
        <div className="space-y-3 rounded-xl border border-dashed border-lia-200 bg-gradient-to-b from-lia-50/60 to-white p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex items-center gap-2 text-sm font-semibold text-lia-800">
                        <PackagePlus className="h-4 w-4" /> Add materials at pick-up
                    </div>
                    <p className="mt-0.5 text-xs text-neutral-500">Search in-stock items, set quantity, or scan a QR. Added lines must be handed out and returned with the rest.</p>
                </div>
                <Button type="button" size="sm" variant="secondary" onClick={() => setScanning((v) => !v)}>
                    <ScanLine className="h-4 w-4" /> {scanning ? 'Hide scanner' : 'Scan QR'}
                </Button>
            </div>

            {error ? <div className="rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-700">{error}</div> : null}

            {replaceable.length > 0 ? (
                <div className="space-y-2 rounded-lg border border-neutral-200 bg-white p-2">
                    <div className="text-xs font-semibold uppercase tracking-wide text-neutral-600">Replace a booked unit</div>
                    <p className="text-[11px] text-neutral-500">Choose the exact in-stock unit. A borrower scan never swaps the booking.</p>
                    {replaceable.map((line) => (
                        <div key={line.id} className="flex items-center gap-2 text-sm">
                            <div className="min-w-0 flex-1">
                                <div className="truncate font-medium">{line.equipment_item?.name || 'Booked item'}</div>
                                <div className="text-[11px] text-neutral-500">{line.equipment_item?.serial_number ? `SN ${line.equipment_item.serial_number}` : 'No serial on the booked line'}</div>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    setError('');
                                    setCandidate(null);
                                    setReplacingId(replacingId === line.id ? null : line.id);
                                    setReplaceScanning(false);
                                }}
                            >
                                <ArrowLeftRight className="h-4 w-4" /> Replace item
                            </Button>
                        </div>
                    ))}
                    {replacing ? (
                        <div className="space-y-2 border-t border-neutral-100 pt-2">
                            <div className="text-xs text-neutral-600">Replacement for {replacing.equipment_item?.name || 'this line'}</div>
                            <Button type="button" size="sm" variant="secondary" onClick={() => setReplaceScanning((v) => !v)}>
                                <ScanLine className="h-4 w-4" /> {replaceScanning ? 'Hide scanner' : 'Scan the exact unit'}
                            </Button>
                            {replaceScanning ? <MiniScanner onToken={onReplaceToken} onClose={() => setReplaceScanning(false)} /> : null}
                            <div className="max-h-48 space-y-2 overflow-y-auto">
                                {(catalog.data?.items || []).filter((item) => item.id !== replacing.equipment_item?.id).map((item) => {
                                    const photo = item.photo_url || item.images[0]?.url;
                                    const blocked = item.unavailable || item.available_qty <= 0;
                                    return (
                                        <button
                                            key={item.id}
                                            type="button"
                                            disabled={blocked}
                                            onClick={() => setCandidate(item)}
                                            className={`flex w-full gap-2 rounded-lg border p-2 text-left ${candidate?.id === item.id ? 'border-lia-600 bg-lia-50' : 'border-neutral-200 bg-white'} ${blocked ? 'opacity-60' : ''}`}
                                        >
                                            <div className="h-12 w-12 shrink-0 overflow-hidden rounded bg-neutral-100">
                                                {photo ? <img src={photo} alt="" className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-[10px] text-neutral-400">No photo</div>}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-medium">{item.name}</div>
                                                <div className="text-[11px] text-neutral-500">
                                                    {item.serial_number ? `SN ${item.serial_number}` : 'No serial'}
                                                    {` · ${item.available_qty} available`}
                                                    {item.condition ? ` · ${item.condition}` : ''}
                                                </div>
                                                {blocked ? <div className="text-[11px] text-red-600">{item.unavailable_reason || 'Not available'}</div> : null}
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                            {candidate ? (
                                <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-2 text-sm text-amber-950">
                                    <div className="font-semibold">Confirm this exact change</div>
                                    <p>
                                        Replace {replacing.equipment_item?.name || 'the booked unit'}
                                        {replacing.equipment_item?.serial_number ? ` (SN ${replacing.equipment_item.serial_number})` : ''} with {candidate.name}
                                        {candidate.serial_number ? ` (SN ${candidate.serial_number})` : ''}. Stock and date conflicts are checked when you confirm.
                                    </p>
                                    <div className="flex gap-2">
                                        <Button type="button" size="sm" variant="secondary" onClick={() => setCandidate(null)}>Cancel</Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={replace.isPending}
                                            onClick={() => replace.mutate({ lineId: replacing.id, equipmentItemId: candidate.id })}
                                        >
                                            {replace.isPending ? 'Replacing…' : 'Confirm replacement'}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            ) : null}

            {scanning ? <MiniScanner onToken={onToken} onClose={() => setScanning(false)} /> : null}

            {selectedAccessories ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900">
                    <div className="flex items-start justify-between gap-2">
                        <div>
                            <div className="font-medium">Usually goes with {selectedAccessories.name}</div>
                            <p className="text-xs">Remind the borrower (and add them here if needed): {selectedAccessories.accessories.map((a) => a.name).join(', ')}</p>
                        </div>
                        <button type="button" aria-label="Dismiss" className="rounded p-1 hover:bg-amber-100" onClick={() => setSelectedAccessories(null)}>
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            ) : null}

            {pickupAdds.length > 0 ? (
                <div className="space-y-1">
                    <div className="text-xs font-semibold uppercase tracking-wide text-lia-700">Added at pick-up (not issued yet)</div>
                    {pickupAdds.map((item) => (
                        <div key={item.id} className="flex items-center gap-2 rounded-lg border border-lia-100 bg-white px-2 py-1.5 text-sm">
                            <div className="min-w-0 flex-1">
                                <div className="truncate font-medium">{item.equipment_item?.name || 'Item'}</div>
                                <div className="text-[11px] text-neutral-500">
                                    Qty {item.qty_approved ?? item.qty_requested}
                                    {item.added_by ? ` · by ${item.added_by}` : ''}
                                </div>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                disabled={remove.isPending}
                                onClick={() => remove.mutate(item.id)}
                                aria-label={`Remove ${item.equipment_item?.name || 'item'}`}
                            >
                                <Trash2 className="h-4 w-4" /> Remove
                            </Button>
                        </div>
                    ))}
                </div>
            ) : null}

            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-neutral-400" />
                <Input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search name, category, serial…"
                    className="pl-8"
                    aria-label="Search equipment to add"
                />
            </div>

            <div className="max-h-64 space-y-2 overflow-y-auto">
                {catalog.isLoading ? <p className="text-xs text-neutral-500">Loading stock…</p> : null}
                {!catalog.isLoading && (catalog.data?.items.length ?? 0) === 0 ? (
                    <p className="text-xs text-neutral-500">No matching in-stock items.</p>
                ) : null}
                {(catalog.data?.items || []).map((item) => {
                    const qty = qtyById[item.id] ?? '1';
                    const photo = item.photo_url || item.images[0]?.url;
                    const blocked = item.unavailable || item.already_on_request || item.available_qty <= 0;
                    return (
                        <div key={item.id} className={`flex gap-2 rounded-xl border p-2 ${blocked ? 'border-neutral-100 bg-neutral-50 opacity-70' : 'border-neutral-200 bg-white'}`}>
                            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
                                {photo ? <img src={photo} alt="" className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-[10px] text-neutral-400">No photo</div>}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">{item.name}</div>
                                <div className="text-[11px] text-neutral-500">
                                    {item.category || 'Equipment'}
                                    {item.serial_number ? ` · SN ${item.serial_number}` : ''}
                                    {` · ${item.available_qty} available`}
                                </div>
                                {item.accessories.length ? (
                                    <div className="mt-0.5 text-[11px] text-amber-800">Goes with: {item.accessories.map((a) => a.name).join(', ')}</div>
                                ) : null}
                                {item.unavailable_reason ? <div className="text-[11px] text-red-600">{item.unavailable_reason}</div> : null}
                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                    <Input
                                        type="number"
                                        min={1}
                                        max={Math.max(item.available_qty, 1)}
                                        value={qty}
                                        disabled={blocked}
                                        onChange={(e) => setQtyById((prev) => ({ ...prev, [item.id]: e.target.value }))}
                                        className="h-8 w-20"
                                        aria-label={`Quantity for ${item.name}`}
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={blocked || add.isPending || Number(qty) < 1}
                                        onClick={() => {
                                            setSelectedAccessories(item.accessories.length ? { name: item.name, accessories: item.accessories } : null);
                                            add.mutate({ equipment_item_id: item.id, qty: Number(qty) || 1 });
                                        }}
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
