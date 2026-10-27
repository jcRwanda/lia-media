import { buildMaterialRequestWhatsappLink, formatBytes, formatPhoneToInternational } from './utils';

test('formatBytes', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(5 * 1024 ** 3)).toBe('5.0 GB');
});

test('formatPhoneToInternational normalises Rwandan numbers', () => {
    expect(formatPhoneToInternational('0788123456')).toBe('250788123456');
    expect(formatPhoneToInternational('+250788123456')).toBe('250788123456');
    expect(formatPhoneToInternational('00250788123456')).toBe('250788123456');
    expect(formatPhoneToInternational('788123456')).toBe('250788123456');
    expect(formatPhoneToInternational(null)).toBeNull();
});

test('buildMaterialRequestWhatsappLink includes summary details', () => {
    const url = buildMaterialRequestWhatsappLink({
        reference_code: 'MR-ABC123',
        full_name: 'Test Requester',
        phone: '0788123456',
        needed_from: '2026-10-27',
        return_by: '2026-10-29',
        project_location: 'Kigali',
        items: [{ equipment_item: { name: 'Camera' }, qty_approved: 2 }],
    });

    expect(url).toContain('https://wa.me/250788123456?text=');
    expect(decodeURIComponent(url!)).toContain('Reference: MR-ABC123');
    expect(decodeURIComponent(url!)).toContain('Camera x2');
    expect(decodeURIComponent(url!)).toContain('Pickup location: Kigali');
});
