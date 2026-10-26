import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useState } from 'react';

export default function EquipmentInventoryPage() {
    const qc = useQueryClient();
    const { data, isLoading } = useQuery({
        queryKey: ['equipment-items'],
        queryFn: async () => {
            const res = await api<any>('/equipment-items');
            return res.items || [];
        },
    });

    const [form, setForm] = useState({ name: '', category: '', serial_number: '', asset_tag: '', condition: '', total_qty: 0, allowed_for_trainees: false });
    const [filterTrainee, setFilterTrainee] = useState(false);

    const create = useMutation({
        mutationFn: async () => {
            const formData = new FormData();
            formData.append('name', form.name);
            formData.append('category', form.category);
            formData.append('serial_number', form.serial_number);
            formData.append('asset_tag', form.asset_tag);
            formData.append('condition', form.condition);
            formData.append('total_qty', String(form.total_qty));
            formData.append('allowed_for_trainees', form.allowed_for_trainees ? '1' : '0');
            const res = await fetch('/api/equipment-items', { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
            setForm({ name: '', category: '', serial_number: '', asset_tag: '', condition: '', total_qty: 0, allowed_for_trainees: false });
        },
    });

    const update = useMutation({
        mutationFn: async ({ id, payload }: { id: number; payload: any }) => {
            const formData = new FormData();
            Object.entries(payload).forEach(([k, v]) => {
                if (typeof v === 'boolean') {
                    formData.append(k, v ? '1' : '0');
                } else if (v !== undefined && v !== null) {
                    formData.append(k, String(v));
                }
            });
            const res = await fetch(`/api/equipment-items/${id}`, { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
        },
    });

    const items = (data || []).filter((it: any) => (filterTrainee ? !!it.allowed_for_trainees : true));

    return (
        <div className="p-4 space-y-4">
            <h1 className="text-xl font-semibold">Equipment Inventory</h1>

            <div className="border rounded p-3 space-y-2">
                <div className="font-medium">Add Item</div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input placeholder="Name" value={form.name} onChange={(e)=>setForm({...form,name:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Category" value={form.category} onChange={(e)=>setForm({...form,category:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Serial" value={form.serial_number} onChange={(e)=>setForm({...form,serial_number:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Asset tag" value={form.asset_tag} onChange={(e)=>setForm({...form,asset_tag:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Condition" value={form.condition} onChange={(e)=>setForm({...form,condition:e.target.value})} className="border rounded p-1 text-sm" />
                    <input type="number" placeholder="Total qty" value={form.total_qty} onChange={(e)=>setForm({...form,total_qty:parseInt(e.target.value)||0})} className="border rounded p-1 text-sm" />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.allowed_for_trainees} onChange={(e)=>setForm({...form,allowed_for_trainees:e.target.checked})} />
                    Allowed for trainees
                </label>
                <button onClick={()=>create.mutate()} className="px-3 py-1 bg-black text-white rounded text-sm">Add</button>
            </div>

            <div className="flex items-center gap-2 text-sm">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={filterTrainee} onChange={(e)=>setFilterTrainee(e.target.checked)} />
                    Filter: allowed for trainees only
                </label>
            </div>

            <div className="overflow-x-auto border rounded">
                <table className="w-full text-sm">
                    <thead className="bg-neutral-50">
                        <tr>
                            <th className="text-left p-2">Name</th>
                            <th className="text-left p-2">Category</th>
                            <th className="text-left p-2">Serial</th>
                            <th className="text-left p-2">Asset Tag</th>
                            <th className="text-left p-2">Condition</th>
                            <th className="text-left p-2">Total Qty</th>
                            <th className="text-left p-2">Allowed for trainees</th>
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading ? (
                            <tr><td className="p-2" colSpan={7}>Loading...</td></tr>
                        ) : items.length === 0 ? (
                            <tr><td className="p-2" colSpan={7}>No items</td></tr>
                        ) : items.map((it: any) => (
                            <tr key={it.id} className="border-t">
                                <td className="p-2">{it.name}</td>
                                <td className="p-2">{it.category}</td>
                                <td className="p-2">{it.serial_number}</td>
                                <td className="p-2">{it.asset_tag}</td>
                                <td className="p-2">{it.condition}</td>
                                <td className="p-2">{it.total_qty}</td>
                                <td className="p-2">
                                    <input type="checkbox" checked={!!it.allowed_for_trainees} onChange={(e)=>update.mutate({ id: it.id, payload: { allowed_for_trainees: e.target.checked } })} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
