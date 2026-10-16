import { describe, expect, it } from 'vitest';
import { allowedActions } from './review';

describe('allowedActions', () => {
    it('hides publish from roles without review.publish', () => {
        const mgmt = (a: string) => a === 'review.approve';
        expect(allowedActions('approved', mgmt)).toEqual(['restricted', 'archived', 'unreviewed']);
        expect(allowedActions('published', mgmt)).toEqual([]);
    });
    it('lets communications publish approved items', () => {
        expect(allowedActions('approved', () => true)).toContain('published');
        expect(allowedActions('unreviewed', () => true)).not.toContain('published');
    });
});
