import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(u.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${u[i]}`;
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-GB', { timeZone: 'Africa/Kigali', dateStyle: 'medium', timeStyle: 'short' });
}

/** Normalise a Rwandan phone number into 250XXXXXXXXX international format for wa.me links. */
export function formatPhoneToInternational(phone: string | null | undefined, defaultCountry = '250'): string | null {
    if (!phone) return null;
    let p = String(phone).replace(/[^0-9]/g, '');
    if (p.startsWith('00')) p = p.slice(2);
    if (p.startsWith(defaultCountry)) return p;
    if (p.startsWith('0')) return defaultCountry + p.slice(1);
    if (p.length === 9) return defaultCountry + p;
    return defaultCountry + p;
}

export function buildMaterialRequestWhatsappLink(req: any): string | null {
    const phone = formatPhoneToInternational(req?.phone);
    if (!phone) return null;
    const items = (req?.items || [])
        .map((it: any) => {
            const name = it.equipment_item?.name || it.name || 'Item';
            const qty = it.qty_approved ?? it.qty_issued ?? it.qty_requested ?? '';
            return qty ? `- ${name} x${qty}` : `- ${name}`;
        })
        .join('\n');
    const pickup = req?.needed_from || '';
    const ret = req?.return_by || '';
    const location =
        [req?.project_location, req?.shoot_location, req?.project_cell, req?.project_sector, req?.project_district, req?.project_province]
            .filter(Boolean)
            .join(', ') || req?.residential_address || '';
    const text = `Booking summary\nReference: ${req?.reference_code}\nName: ${req?.full_name}\nItems:\n${items}\nPickup: ${pickup}\nReturn: ${ret}\nPickup location: ${location}`;
    return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
}
