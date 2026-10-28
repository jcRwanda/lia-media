import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';
import { useEffect, useMemo, useState } from 'react';
import QRCode from 'qrcode';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/card';

interface Item {
    id: number;
    name: string;
    category: string | null;
    serial_number: string | null;
    asset_tag: string | null;
    total_qty: number;
    allowed_for_trainees: boolean;
    qr_url: string | null;
    can_delete?: boolean;
}

const LOGO = '/images/lia-logo-white.jpg';
let logoPromise: Promise<HTMLImageElement> | null = null;
function loadLogo(): Promise<HTMLImageElement> {
    if (!logoPromise) {
        logoPromise = new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = LOGO;
        });
    }
    return logoPromise;
}

/** QR (error correction H) with the LIA logo composited in the centre on a white rounded plate. */
async function brandedQr(url: string): Promise<string> {
    const size = 600;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    await QRCode.toCanvas(canvas, url, { errorCorrectionLevel: 'H', margin: 2, width: size, color: { dark: '#111827', light: '#ffffff' } });
    const ctx = canvas.getContext('2d');
    try {
        const logo = await loadLogo();
        if (ctx) {
            const plate = size * 0.24;
            const x = (size - plate) / 2;
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.roundRect(x, x, plate, plate, plate * 0.18);
            ctx.fill();
            const pad = plate * 0.08;
            ctx.drawImage(logo, x + pad, x + pad, plate - pad * 2, plate - pad * 2);
        }
    } catch {
        /* logo optional */
    }
    return canvas.toDataURL('image/png');
}

function Label({ item }: { item: Item }) {
    const [src, setSrc] = useState('');
    useEffect(() => {
        if (item.qr_url) brandedQr(item.qr_url).then(setSrc);
    }, [item.qr_url]);

    return (
        <div className="lia-cut">
            <div className="lia-label">
                <div className="lia-band">
                    <img src={LOGO} alt="" />
                    <span>Love in Arts · LIA Media</span>
                </div>
                {src ? <img src={src} alt={`QR ${item.name}`} className="lia-qr" data-qr={item.qr_url || ''} /> : <div className="lia-qr bg-neutral-100" />}
                <div className="lia-name">{item.name}</div>
                <div className="lia-sn">
                    SN: {item.serial_number || '—'}
                    {item.asset_tag ? ` · ${item.asset_tag}` : ''}
                </div>
                <div className="lia-pill">Scan to confirm pickup / return</div>
            </div>
        </div>
    );
}

const CSS = `
.lia-sheet { display: grid; grid-template-columns: repeat(auto-fill, 50mm); gap: 0; justify-content: center; }
.lia-cut { width: 50mm; height: 70mm; box-sizing: border-box; padding: 1.5mm; border: 0.2mm dashed #9ca3af; break-inside: avoid; page-break-inside: avoid; }
.lia-label { height: 100%; box-sizing: border-box; border: 0.5mm solid #b91c1c; border-radius: 3mm; overflow: hidden; background: #fff; text-align: center; display: flex; flex-direction: column; align-items: center; font-family: Inter, system-ui, sans-serif; }
.lia-band { width: 100%; background: #b91c1c; color: #fff; display: flex; align-items: center; gap: 1.5mm; padding: 1mm 2mm; box-sizing: border-box; font-size: 2.4mm; font-weight: 700; }
.lia-band img { width: 6mm; height: 6mm; border-radius: 1mm; }
.lia-qr { width: 34mm; height: 34mm; margin-top: 2mm; }
.lia-name { font-size: 2.7mm; font-weight: 700; color: #111827; margin: 1mm 2mm 0; line-height: 1.25; max-height: 6.8mm; overflow: hidden; }
.lia-sn { font-size: 2.1mm; color: #4b5563; margin-top: 0.5mm; }
.lia-pill { margin-top: auto; margin-bottom: 2mm; background: #fdf2f2; color: #991b1b; border: 0.2mm solid #f5c2c2; border-radius: 2mm; padding: 0.6mm 2mm; font-size: 2mm; font-weight: 700; }
.lia-label, .lia-band, .lia-pill, .lia-qr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
@media print {
  @page { size: A4; margin: 8mm; }
  html, body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  aside, header, nav, .no-print { display: none !important; }
  main { padding: 0 !important; margin: 0 !important; }
  .lia-sheet { grid-template-columns: repeat(3, 50mm); justify-content: center; }
  .lia-cut:nth-child(12n) { break-after: page; page-break-after: always; }
}`;

export default function EquipmentLabelsPage() {
    const [params, setParams] = useSearchParams();
    const ids = (params.get('ids') || '').split(',').filter(Boolean).map(Number);
    const [category, setCategory] = useState('');
    const [trainee, setTrainee] = useState('');
    const [inStock, setInStock] = useState(true);
    const [downloading, setDownloading] = useState(false);
    const [error, setError] = useState('');
    const { data, isLoading } = useQuery({
        queryKey: ['equipment-items'],
        queryFn: async () => (await api<{ items: Item[] }>('/equipment-items')).items || [],
    });
    const all = data || [];
    const isAdmin = all.some((it) => it.can_delete);
    const categories = useMemo(() => Array.from(new Set(all.map((i) => i.category).filter(Boolean))) as string[], [all]);
    const items = all.filter(
        (it) =>
            (ids.length ? ids.includes(it.id) : true) &&
            (category ? it.category === category : true) &&
            (trainee === '' ? true : it.allowed_for_trainees === (trainee === '1')) &&
            (inStock ? it.total_qty > 0 : true),
    );

    async function downloadPdf() {
        setDownloading(true);
        setError('');
        try {
            const q = new URLSearchParams();
            if (ids.length) q.set('ids', ids.join(','));
            if (category) q.set('category', category);
            if (trainee !== '') q.set('trainee', trainee);
            q.set('in_stock', inStock ? '1' : '0');
            const res = await fetch(`/api/equipment-labels/pdf?${q}`, { credentials: 'same-origin', headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(res.status === 403 ? 'Only an admin can download the PDF.' : `Download failed (${res.status})`);
            const blob = await res.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `lia-equipment-labels-${new Date().toISOString().slice(0, 10)}.pdf`;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 5000);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Download failed');
        } finally {
            setDownloading(false);
        }
    }

    const select = 'h-9 rounded-md border border-neutral-300 bg-white px-2 text-sm focus:border-lia-500 focus:outline-none focus:ring-2 focus:ring-lia-200';

    return (
        <div className="space-y-4">
            <style>{CSS}</style>
            <div className="no-print space-y-3">
                <PageHeader
                    title={ids.length === 1 ? 'QR label' : 'QR labels'}
                    description="Branded 50×70 mm stickers. Print on A4 (12 per sheet) and cut along the dashed lines. Borrowers scan to confirm pickup; storekeepers scan to check items."
                    actions={
                        <div className="flex gap-2">
                            {isAdmin ? (
                                <Button size="sm" variant="secondary" onClick={downloadPdf} disabled={downloading}>
                                    {downloading ? 'Preparing…' : 'Download A4 PDF'}
                                </Button>
                            ) : null}
                            <Button size="sm" onClick={() => window.print()}>Print</Button>
                        </div>
                    }
                />
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-lia-100 bg-lia-50 p-3 text-sm">
                    <select className={select} value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Category">
                        <option value="">All categories</option>
                        {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <select className={select} value={trainee} onChange={(e) => setTrainee(e.target.value)} aria-label="Trainee access">
                        <option value="">Trainee: any</option>
                        <option value="1">Trainee-allowed only</option>
                        <option value="0">Not for trainees</option>
                    </select>
                    <label className="flex items-center gap-1.5"><input type="checkbox" checked={inStock} onChange={(e) => setInStock(e.target.checked)} className="accent-lia-600" /> In stock only</label>
                    {ids.length ? (
                        <button type="button" className="rounded-full bg-white px-3 py-1 text-xs font-medium text-lia-700 ring-1 ring-lia-200" onClick={() => { params.delete('ids'); setParams(params); }}>
                            {ids.length} selected ✕
                        </button>
                    ) : null}
                    <span className="ml-auto text-neutral-600">{items.length} label{items.length === 1 ? '' : 's'}</span>
                </div>
                {error ? <p className="text-sm text-lia-700">{error}</p> : null}
            </div>
            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            {!isLoading && !items.length ? <p className="text-sm text-neutral-500">No materials match these filters.</p> : null}
            <div className="lia-sheet">{items.map((it) => <Label key={it.id} item={it} />)}</div>
        </div>
    );
}
