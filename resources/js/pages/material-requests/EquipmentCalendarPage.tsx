import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, fieldErrors } from '@/lib/api';

type CalendarItem = { id: number; name: string; total_qty: number };
type BookingItem = {
    equipment_item_id: number;
    name: string | null;
    qty_requested: number | null;
    qty_approved: number | null;
    qty_issued: number | null;
};
type CalendarBooking = {
    id: number;
    reference_code: string;
    status: string;
    source: string | null;
    source_detail: string | null;
    requester_type: string;
    needed_from: string;
    return_by: string;
    items: BookingItem[];
};
type CalendarBlock = {
    id: number;
    equipment_item_id: number | null;
    item_name: string | null;
    starts_on: string;
    ends_on: string;
    reason: string;
    created_by: string | null;
};
type Rules = { closed_weekdays: number[]; max_booking_days: number; lead_time_days: number };
type CalendarData = {
    month: string;
    starts_on: string;
    ends_on: string;
    items: CalendarItem[];
    bookings: CalendarBooking[];
    blocks: CalendarBlock[];
    rules: Rules;
};

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function shiftMonth(month: string, delta: number): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, monthNumber - 1 + delta, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function dayKey(month: string, day: number): string {
    return `${month}-${String(day).padStart(2, '0')}`;
}

function weekdayIndex(key: string): number {
    return new Date(`${key}T00:00:00Z`).getUTCDay();
}

export default function EquipmentCalendarPage() {
    const qc = useQueryClient();
    const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
    const [rules, setRules] = useState<Rules | null>(null);
    const [blockForm, setBlockForm] = useState({ equipment_item_id: '', starts_on: '', ends_on: '', reason: '' });
    const [error, setError] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['equipment-calendar', month],
        queryFn: async () => api<CalendarData>(`/equipment-calendar?month=${month}`),
    });

    useEffect(() => {
        if (data?.rules) setRules(data.rules);
    }, [data?.rules]);

    const refresh = () => qc.invalidateQueries({ queryKey: ['equipment-calendar', month] });

    const createBlock = useMutation({
        mutationFn: async () => api('/equipment-blocks', {
            method: 'POST',
            json: {
                equipment_item_id: blockForm.equipment_item_id ? Number(blockForm.equipment_item_id) : null,
                starts_on: blockForm.starts_on,
                ends_on: blockForm.ends_on,
                reason: blockForm.reason,
            },
        }),
        onSuccess: () => {
            setBlockForm({ equipment_item_id: '', starts_on: '', ends_on: '', reason: '' });
            setError('');
            refresh();
        },
        onError: (e) => setError(Object.values(fieldErrors(e))[0] || (e as Error).message),
    });

    const removeBlock = useMutation({
        mutationFn: async (id: number) => api(`/equipment-blocks/${id}`, { method: 'DELETE' }),
        onSuccess: refresh,
    });

    const saveRules = useMutation({
        mutationFn: async () => api('/equipment-calendar/rules', {
            method: 'PATCH',
            json: {
                closed_weekdays: rules?.closed_weekdays || [],
                max_booking_days: Number(rules?.max_booking_days || 30),
                lead_time_days: Number(rules?.lead_time_days || 0),
            },
        }),
        onSuccess: () => {
            setError('');
            refresh();
        },
        onError: (e) => setError(Object.values(fieldErrors(e))[0] || (e as Error).message),
    });

    const days = data ? Number(data.ends_on.slice(8, 10)) : 0;
    const dayNumbers = Array.from({ length: days }, (_, index) => index + 1);

    const bookingsFor = (itemId: number, key: string) => {
        if (!data) return [];
        return data.bookings.flatMap((booking) => (
            booking.needed_from <= key && booking.return_by >= key
                ? booking.items.filter((item) => item.equipment_item_id === itemId).map((item) => ({ booking, item }))
                : []
        ));
    };

    const blocksFor = (itemId: number | null, key: string) => {
        if (!data) return [];
        return data.blocks.filter((block) => (
            block.starts_on <= key
            && block.ends_on >= key
            && (block.equipment_item_id === null || block.equipment_item_id === itemId)
        ));
    };

    const toggleWeekday = (weekday: number) => {
        if (!rules) return;
        const closed = rules.closed_weekdays.includes(weekday)
            ? rules.closed_weekdays.filter((day) => day !== weekday)
            : [...rules.closed_weekdays, weekday];
        setRules({ ...rules, closed_weekdays: closed.sort() });
    };

    return (
        <div className="space-y-4 p-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold">Equipment calendar</h1>
                    <p className="text-sm text-neutral-500">Approved and issued bookings, blocks and booking rules.</p>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={() => setMonth(shiftMonth(month, -1))} className="rounded border px-2 py-1 text-sm">←</button>
                    <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="rounded border px-2 py-1 text-sm" />
                    <button onClick={() => setMonth(shiftMonth(month, 1))} className="rounded border px-2 py-1 text-sm">→</button>
                </div>
            </div>

            {error && <div className="rounded border border-red-200 bg-red-50 p-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-x-auto rounded border">
                <table className="w-full min-w-[980px] text-xs">
                    <thead className="bg-neutral-50">
                        <tr>
                            <th className="sticky left-0 z-10 min-w-40 bg-neutral-50 p-2 text-left">Item</th>
                            {dayNumbers.map((day) => (
                                <th key={day} className="min-w-7 p-1 text-center font-normal text-neutral-500">
                                    <div>{day}</div>
                                    <div className="text-[10px]">{WEEKDAYS[weekdayIndex(dayKey(month, day))]}</div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading ? (
                            <tr><td className="p-3" colSpan={days + 1}>Loading...</td></tr>
                        ) : !data || data.items.length === 0 ? (
                            <tr><td className="p-3" colSpan={days + 1}>No equipment items yet.</td></tr>
                        ) : data.items.map((item) => (
                            <tr key={item.id} className="border-t">
                                <td className="sticky left-0 z-10 bg-white p-2">
                                    <div className="font-medium">{item.name}</div>
                                    <div className="text-[11px] text-neutral-500">{item.total_qty} in stock</div>
                                </td>
                                {dayNumbers.map((day) => {
                                    const key = dayKey(month, day);
                                    const cellBookings = bookingsFor(item.id, key);
                                    const cellBlocks = blocksFor(item.id, key);
                                    const closed = data.rules.closed_weekdays.includes(weekdayIndex(key));
                                    const bookedQty = cellBookings.reduce((total, entry) => (
                                        total + Number(entry.item.qty_approved ?? entry.item.qty_issued ?? entry.item.qty_requested ?? 0)
                                    ), 0);
                                    const title = [
                                        ...cellBookings.map((entry) => `${entry.booking.reference_code} (${entry.booking.status}) — ${entry.booking.source || 'Direct/Hosted form'}`),
                                        ...cellBlocks.map((block) => `Blocked: ${block.reason}`),
                                    ].join('\n');

                                    return (
                                        <td key={key} title={title || undefined} className="p-0.5 align-top">
                                            <div className={`flex h-8 items-center justify-center rounded text-[9px] font-medium ${
                                                cellBlocks.length > 0
                                                    ? 'bg-red-100 text-red-700'
                                                    : cellBookings.length > 0
                                                        ? 'bg-lia-100 text-lia-700'
                                                        : closed
                                                            ? 'bg-neutral-100 text-neutral-400'
                                                            : 'text-neutral-300'
                                            }`}>
                                                {cellBlocks.length > 0 ? 'BLK' : cellBookings.length > 0 ? bookedQty : closed ? '—' : ''}
                                            </div>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-3 rounded border p-3">
                    <div className="font-medium">Block dates</div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <select value={blockForm.equipment_item_id} onChange={(e) => setBlockForm({ ...blockForm, equipment_item_id: e.target.value })} className="rounded border px-2 py-1 text-sm">
                            <option value="">All equipment (global)</option>
                            {data?.items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                        <input value={blockForm.reason} onChange={(e) => setBlockForm({ ...blockForm, reason: e.target.value })} placeholder="Reason" className="rounded border px-2 py-1 text-sm" />
                        <label className="text-xs text-neutral-500">From<input type="date" value={blockForm.starts_on} onChange={(e) => setBlockForm({ ...blockForm, starts_on: e.target.value, ends_on: blockForm.ends_on || e.target.value })} className="mt-1 w-full rounded border px-2 py-1 text-sm text-neutral-900" /></label>
                        <label className="text-xs text-neutral-500">To<input type="date" value={blockForm.ends_on} onChange={(e) => setBlockForm({ ...blockForm, ends_on: e.target.value })} className="mt-1 w-full rounded border px-2 py-1 text-sm text-neutral-900" /></label>
                    </div>
                    <button onClick={() => createBlock.mutate()} disabled={createBlock.isPending || !blockForm.starts_on || !blockForm.ends_on || !blockForm.reason} className="rounded bg-black px-3 py-1 text-sm text-white disabled:opacity-40">Add block</button>

                    <div className="space-y-1">
                        {(data?.blocks || []).map((block) => (
                            <div key={block.id} className="flex items-start justify-between gap-2 rounded bg-neutral-50 p-2 text-sm">
                                <div>
                                    <div className="font-medium">{block.equipment_item_id === null ? 'All equipment' : block.item_name}</div>
                                    <div className="text-xs text-neutral-500">{block.starts_on} → {block.ends_on} · {block.reason}</div>
                                </div>
                                <button onClick={() => removeBlock.mutate(block.id)} className="text-xs text-red-600 hover:underline">Remove</button>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="space-y-3 rounded border p-3">
                    <div className="font-medium">Booking rules</div>
                    <div>
                        <div className="mb-1 text-xs text-neutral-500">Closed weekdays</div>
                        <div className="flex flex-wrap gap-2">
                            {WEEKDAYS.map((label, weekday) => (
                                <label key={label} className="flex items-center gap-1 text-sm">
                                    <input type="checkbox" checked={rules?.closed_weekdays.includes(weekday) || false} onChange={() => toggleWeekday(weekday)} />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <label className="text-xs text-neutral-500">Max booking days<input type="number" min={1} max={365} value={rules?.max_booking_days ?? 30} onChange={(e) => rules && setRules({ ...rules, max_booking_days: Number(e.target.value) })} className="mt-1 w-full rounded border px-2 py-1 text-sm text-neutral-900" /></label>
                        <label className="text-xs text-neutral-500">Lead time days<input type="number" min={0} max={365} value={rules?.lead_time_days ?? 0} onChange={(e) => rules && setRules({ ...rules, lead_time_days: Number(e.target.value) })} className="mt-1 w-full rounded border px-2 py-1 text-sm text-neutral-900" /></label>
                    </div>
                    <button onClick={() => saveRules.mutate()} disabled={saveRules.isPending || !rules} className="rounded bg-lia-700 px-3 py-1 text-sm text-white disabled:opacity-40">Save rules</button>
                    <p className="text-xs text-neutral-500">Rules apply to new submissions. Existing approved bookings stay on the calendar.</p>
                </div>
            </div>
        </div>
    );
}
