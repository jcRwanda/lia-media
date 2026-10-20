import { Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { MAX_ZIP_ITEMS } from '@/lib/gallery';

interface SelectionBarProps {
    count: number;
    canDownload: boolean;
    busy?: boolean;
    tooMany?: boolean;
    notice?: string | null;
    onSelectAll: () => void;
    onClear: () => void;
    onCancel: () => void;
    onDownload?: () => void;
    children?: ReactNode;
}

/** Fixed count bar for multi-select mode: how many are picked, select all/clear, and download. */
export default function SelectionBar({ count, canDownload, busy = false, tooMany = false, notice, onSelectAll, onClear, onCancel, onDownload, children }: SelectionBarProps) {
    return (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-2 px-4 py-3">
                <span className="mr-auto text-sm font-medium">{count} selected</span>
                <Button size="sm" variant="ghost" onClick={onSelectAll}>Select all</Button>
                <Button size="sm" variant="ghost" disabled={!count} onClick={onClear}>Clear</Button>
                {children}
                <Button size="sm" variant="secondary" onClick={onCancel}>Cancel</Button>
                {canDownload ? (
                    <Button size="sm" disabled={!count || busy || tooMany} onClick={onDownload}>
                        <Download className="h-4 w-4" />{busy ? 'Preparing…' : 'Download selected'}
                    </Button>
                ) : null}
            </div>
            {tooMany ? <p className="mx-auto max-w-7xl px-4 pb-2 text-xs text-lia-700">Select at most {MAX_ZIP_ITEMS} items at a time.</p> : null}
            {notice ? <p role="alert" className="mx-auto max-w-7xl px-4 pb-2 text-xs text-lia-700">{notice}</p> : null}
        </div>
    );
}
