import type { ReviewState } from '@/lib/types';

export const TRANSITIONS: Record<ReviewState, ReviewState[]> = {
    unreviewed: ['approved', 'restricted', 'archived'],
    approved: ['published', 'restricted', 'archived', 'unreviewed'],
    published: ['approved', 'restricted', 'archived'],
    restricted: ['unreviewed', 'approved', 'archived'],
    archived: ['unreviewed'],
};

export const ACTION_LABEL: Record<ReviewState, string> = {
    unreviewed: 'Send back to review', approved: 'Approve', published: 'Publish', restricted: 'Restrict', archived: 'Archive',
};

export function abilityFor(from: ReviewState, to: ReviewState): string {
    return to === 'published' || from === 'published' ? 'review.publish' : 'review.approve';
}

/** Actions the current user may take on an item in `from`. */
export function allowedActions(from: ReviewState, can: (a: string) => boolean): ReviewState[] {
    return TRANSITIONS[from].filter((to) => can(abilityFor(from, to)));
}
