import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';
import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/card';

interface Item {
    id: number;
    name: string;
    serial_number: string | null;
    asset_tag: string | null;
    qr_url: string | null;
}

function Label({ item }: { item: Item }) {
    const [src, setSrc] = useState('');
    useEffect(() => {
        if (item.qr_url) QRCode.toDataURL(item.qr_url, { margin: 1, width: 320, errorCorrectionLevel: 'M' }).then(setSrc);
    }, [item.qr_url]);

    return (
        <div className="qr-label flex items-center gap-3 rounded-lg border bg-white p-3" style={{ breakInside: 'avoid' }}>
            {src ? <img src={src} alt={`QR ${item.name}`} className="h-28 w-28" /> : <div className="h-28 w-28 bg-neutral-100" />}
            <div className="min-w-0 text-sm">
                <div className="font-semibold">{item.name}</div>
                <div className="text-xs">SN: {item.serial_number || '—'}</div>
                {item.asset_tag ? <div className="text-xs">Tag: {item.asset_tag}</div> : null}
                <div className="mt-1 text-[10px] text-neutral-500">Love in Arts · scan to confirm</div>
            </div>
        </div>
    );
}

export default function EquipmentLabelsPage() {
    const [params] = useSearchParams();
    const ids = (params.get('ids') || '').split(',').filter(Boolean).map(Number);
    const { data, isLoading } = useQuery({
        queryKey: ['equipment-items'],
        queryFn: async () => (await api<{ items: Item[] }>('/equipment-items')).items || [],
    });
    const items = (data || []).filter((it) => (ids.length ? ids.includes(it.id) : true));

    return (
        <div className="space-y-4">
            <style>{`@media print { aside, header, nav, .no-print { display: none !important; } main { padding: 0 !important; } }`}</style>
            <div className="no-print">
                <PageHeader
                    title={ids.length === 1 ? 'QR label' : 'QR labels'}
                    description="Print and stick these on the equipment. Borrowers scan to confirm pickup; storekeepers scan to check items."
                    actions={<Button size="sm" onClick={() => window.print()}>Print</Button>}
                />
            </div>
            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 print:grid-cols-2">
                {items.map((it) => <Label key={it.id} item={it} />)}
            </div>
        </div>
    );
}
