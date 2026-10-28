import { describe, expect, it } from 'vitest';
import { normalizePhone } from './phone';

describe('normalizePhone', () => {
    it('normalizes Rwandan formats', () => {
        expect(normalizePhone('0788 123 456')).toBe('+250788123456');
        expect(normalizePhone('788123456')).toBe('+250788123456');
        expect(normalizePhone('+250-788-123-456')).toBe('+250788123456');
        expect(normalizePhone('00250788123456')).toBe('+250788123456');
        expect(normalizePhone('+1 415 555 0123')).toBe('+14155550123');
    });
    it('rejects junk', () => {
        expect(normalizePhone('12345')).toBeNull();
        expect(normalizePhone('07881x3456')).toBeNull();
        expect(normalizePhone('+25078812345')).toBeNull();
    });
});
