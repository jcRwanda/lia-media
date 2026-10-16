import { FileText, Film, Music, Image as ImageIcon, CheckSquare, Square } from 'lucide-react';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/card';
import type { AssetCard } from '@/lib/types';
import { cn } from '@/lib/utils';

export const reviewTone: Record<string, string> = { unreviewed: 'neutral', approved: 'blue', published: 'green', restricted: 'red', archived: 'violet' };

const icons = { image: ImageIcon, video: Film, audio: Music, document: FileText, other: FileText };

export function ReviewBadge({ state }: { state: string }) {
    return <Badge tone={reviewTone[state]} className="capitalize">{state}</Badge>;
}

export default function AssetTile({ asset, selected, onToggle, to }: { asset: AssetCard; selected?: boolean; onToggle?: () => void; to?: string }) {
    const Icon = icons[asset.kind] ?? FileText;
    return (
        <div className={cn('group relative overflow-hidden rounded-xl border bg-white', selected ? 'border-lia-500 ring-2 ring-lia-500/30' : 'border-neutral-200')}>
            <Link to={to ?? `/assets/${asset.uuid}`} className="block">
                <div className="aspect-[4/3] bg-neutral-100">
                    {asset.thumb_url ? <img src={asset.thumb_url} alt={asset.title} loading="lazy" className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-neutral-400"><Icon className="h-8 w-8" /></div>}
                </div>
                <div className="p-2">
                    <div className="truncate text-xs font-medium">{asset.title}</div>
                    <div className="mt-1 flex items-center gap-1"><ReviewBadge state={asset.review_state} />{asset.kind === 'video' ? <Badge>video</Badge> : null}</div>
                </div>
            </Link>
            {onToggle ? (
                <button type="button" onClick={onToggle} aria-label={selected ? 'Deselect' : 'Select'} className={cn('absolute left-2 top-2 rounded bg-white/90 p-0.5 text-lia-700 shadow', selected ? 'opacity-100' : 'opacity-70 sm:opacity-0 sm:group-hover:opacity-100')}>
                    {selected ? <CheckSquare className="h-4 w-4" /> : <Square className="h-4 w-4" />}
                </button>
            ) : null}
        </div>
    );
}
