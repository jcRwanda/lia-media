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

    const [form, setForm] = useState({ name: '', category: '', serial_number: '', asset_tag: '', condition: '', total_qty: 0 });

    const create = useMutation({
        mutationFn: async () => {
            const formData = new FormData();
            Object.entries(form).forEach(([k,v]) => formData.append(k, String(v)));
            const res = await fetch('/api/equipment-items', { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
            setForm({ name: '', category: '', serial_number: '', asset_tag: '', condition: '', total_qty: 0 });
        },
    });

    const items = data || [];

    return (
        <div className="p-4 space-y-4">
            <h1 className="text-xl font-semibold">Equipment Inventory</h1>

            <div className="border rounded p-3 space-y-2">
                <div className="font-medium">Add Item</div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input placeholder="Name" value={form.name} onChange={(e)=>setForm({...form,name:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Category" value={form.category} onChange={(e)=>setForm({...form,category:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Serial/Asset tag" value={form.serial_number} onChange={(e)=>setForm({...form,serial_number:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Asset tag" value={form.asset_tag} onChange={(e)=>setForm({...form,asset_tag:e.target.value})} className="border rounded p-1 text-sm" />
                    <input placeholder="Condition" value={form.condition} onChange={(e)=>setForm({...form,condition:e.target.value})} className="border rounded p-1 text-sm" />
                    <input type="number" placeholder="Total qty" value={form.total_qty} onChange={(e)=>setForm({...form,total_qty:parseInt(e.target.value)||0})} className="border rounded p-1 text-sm" />
                </div>
                <button onClick={()=>create.mutate()} className="px-3 py-1 bg-black text-white rounded text-sm">Add</button>
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
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading ? (
                            <tr><td className="p-2" colSpan={6}>Loading...</td></tr>
                        ) : items.length === 0 ? (
                            <tr><td className="p-2" colSpan={6}>No items</td></tr>
                        ) : items.map((it: any) => (
                            <tr key={it.id} className="border-t">
                                <td className="p-2">{it.name}</td>
                                <td className="p-2">{it.category}</td>
                                <td className="p-2">{it.serial_number}</td>
                                <td className="p-2">{it.asset_tag}</td>
                                <td className="p-2">{it.condition}</td>
                                <td className="p-2">{it.total_qty}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
