import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

export function CopyField({ value, label }: { value: string; label: string }) {
    const [done, setDone] = useState(false);
    return (
        <div className="space-y-1">
            <div className="text-xs font-medium text-neutral-600">{label}</div>
            <div className="flex gap-2">
                <input readOnly value={value} className="h-9 min-w-0 flex-1 rounded-lg border border-neutral-200 bg-neutral-50 px-2 font-mono text-xs" onFocus={(e) => e.target.select()} />
                <Button type="button" size="sm" variant="secondary" onClick={async () => { await navigator.clipboard?.writeText(value).catch(() => null); setDone(true); setTimeout(() => setDone(false), 1500); }}>
                    {done ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                </Button>
            </div>
        </div>
    );
}
