import { useMutation, useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface PublicItem {
    name: string;
    category: string | null;
    serial_hint: string | null;
    photo_url: string | null;
}

interface ConfirmRes {
    confirmed_at: string | null;
    booking: { reference_code: string; full_name: string; needed_from: string | null; return_by: string | null };
}

export default function PublicQrPage() {
    const { token = '' } = useParams();
    const [contact, setContact] = useState('');
    const { data, isLoading, error } = useQuery({
        queryKey: ['qr', token],
        queryFn: () => api<{ item: PublicItem }>(`/v1/qr/${token}`),
        retry: false,
    });
    const confirm = useMutation({
        mutationFn: () => api<ConfirmRes>(`/v1/qr/${token}/confirm`, { method: 'POST', json: { contact } }),
    });

    const item = data?.item;
    const confirmError = confirm.error instanceof ApiError ? confirm.error.message : confirm.error ? 'Something went wrong. Try again.' : '';

    return (
        <div className="mx-auto min-h-screen max-w-md space-y-4 bg-neutral-50 p-4">
            <h1 className="text-lg font-semibold">LIA Media · Equipment pickup</h1>
            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            {error ? <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">This QR code is not recognised.</p> : null}
            {item ? (
                <div className="space-y-4 rounded-xl border bg-white p-4">
                    {item.photo_url ? <img src={item.photo_url} alt={item.name} className="h-48 w-full rounded-lg object-cover" /> : null}
                    <div>
                        <div className="text-base font-semibold">{item.name}</div>
                        <div className="text-xs text-neutral-500">{[item.category, item.serial_hint ? `SN ${item.serial_hint}` : null].filter(Boolean).join(' · ')}</div>
                    </div>
                    {confirm.data ? (
                        <div className="space-y-1 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                            <div className="flex items-center gap-2 font-medium"><CheckCircle2 className="h-4 w-4" /> Pickup confirmed</div>
                            <div>Booking {confirm.data.booking.reference_code} · {confirm.data.booking.full_name}</div>
                            {confirm.data.booking.return_by ? <div>Please return by {confirm.data.booking.return_by}.</div> : null}
                        </div>
                    ) : (
                        <form
                            className="space-y-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (contact.trim()) confirm.mutate();
                            }}
                        >
                            <label className="block text-sm">Enter the phone number or email you used on your request</label>
                            <Input value={contact} onChange={(e) => setContact(e.target.value)} placeholder="07… or you@example.com" autoComplete="tel" />
                            {confirmError ? <p className="text-xs text-red-600">{confirmError}</p> : null}
                            <Button type="submit" className="w-full" disabled={confirm.isPending || !contact.trim()}>
                                {confirm.isPending ? 'Checking…' : 'I received this'}
                            </Button>
                        </form>
                    )}
                </div>
            ) : null}
        </div>
    );
}
