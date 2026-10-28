/** Mirrors App\Support\Phone::normalize — returns E.164 (+2507XXXXXXXX) or null when invalid. */
export function normalizePhone(raw: string): string | null {
    const v = raw.trim();
    if (!v || /[^\d\s+().-]/.test(v)) return null;
    let plus = v.startsWith('+');
    let d = v.replace(/\D+/g, '');
    if (!plus && d.startsWith('00')) {
        d = d.slice(2);
        plus = true;
    }
    if (!plus) {
        if (/^07\d{8}$/.test(d)) return `+250${d.slice(1)}`;
        if (/^7\d{8}$/.test(d)) return `+250${d}`;
        if (/^2507\d{8}$/.test(d)) return `+${d}`;
        return null;
    }
    if (d.startsWith('250')) return /^2507\d{8}$/.test(d) ? `+${d}` : null;
    return /^[1-9]\d{7,14}$/.test(d) ? `+${d}` : null;
}

export const isValidPhone = (raw: string) => normalizePhone(raw) !== null;
