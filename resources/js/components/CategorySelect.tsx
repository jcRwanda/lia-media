import { useState } from 'react';
import { Input } from '@/components/ui/input';

const NEW = '__new__';

export function CategorySelect({ value, options, onChange }: { value: string; options: string[]; onChange: (value: string) => void }) {
    const known = value === '' || options.includes(value);
    const [adding, setAdding] = useState(!known);
    const selectValue = adding ? NEW : value;

    return (
        <div className="flex flex-col gap-2">
            <select
                aria-label="Category"
                className="h-9 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm"
                value={selectValue}
                onChange={(e) => {
                    if (e.target.value === NEW) {
                        setAdding(true);
                        onChange('');
                    } else {
                        setAdding(false);
                        onChange(e.target.value);
                    }
                }}
            >
                <option value="">No category</option>
                {options.map((c) => (
                    <option key={c} value={c}>
                        {c}
                    </option>
                ))}
                <option value={NEW}>Add new category…</option>
            </select>
            {adding ? <Input autoFocus placeholder="New category name" value={value} onChange={(e) => onChange(e.target.value)} /> : null}
        </div>
    );
}
