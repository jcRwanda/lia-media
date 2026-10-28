import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, fieldErrors } from '@/lib/api';
import { compressImages } from '@/lib/images';
import { CategorySelect } from '@/components/CategorySelect';
import { ProofUploader } from '@/components/ProofUploader';
import { Badge, Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useMemo, useState } from 'react';
import { Link } from 'react-router';

interface EquipmentImage {
    id: number;
    name: string | null;
    url: string | null;
}

interface EquipmentItem {
    id: number;
    name: string;
    category: string | null;
    serial_number: string | null;
    asset_tag: string | null;
    condition: string | null;
    total_qty: number;
    allowed_for_trainees: boolean;
    photo_url: string | null;
    images: EquipmentImage[];
    qr_url?: string | null;
    can_edit_serial?: boolean;
    can_delete?: boolean;
}

interface EquipmentForm {
    name: string;
    category: string;
    serial_number: string;
    asset_tag: string;
    condition: string;
    total_qty: string;
    allowed_for_trainees: boolean;
    files: File[];
}

const emptyForm: EquipmentForm = {
    name: '',
    category: '',
    serial_number: '',
    asset_tag: '',
    condition: '',
    total_qty: '0',
    allowed_for_trainees: false,
    files: [],
};

function describeError(e: unknown, fallback: string): string {
    const errors = fieldErrors(e);
    const lines = Object.values(errors).map((msg) => msg.replace(/\b(photos|images)\.(\d+)\b/g, (_, _f, n) => `photo ${Number(n) + 1}`));
    if (lines.length) return lines.join('\n');
    return e instanceof Error ? e.message : fallback;
}

async function formToData(form: EquipmentForm): Promise<FormData> {
    const data = new FormData();
    data.append('name', form.name);
    data.append('category', form.category);
    data.append('serial_number', form.serial_number);
    data.append('asset_tag', form.asset_tag);
    data.append('condition', form.condition);
    data.append('total_qty', form.total_qty || '0');
    data.append('allowed_for_trainees', form.allowed_for_trainees ? '1' : '0');
    const files = await compressImages(form.files);
    files.forEach((file, index) => data.append(`photos[${index}]`, file));

    return data;
}

export default function EquipmentInventoryPage() {
    const qc = useQueryClient();
    const [form, setForm] = useState<EquipmentForm>(emptyForm);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<EquipmentForm>(emptyForm);
    const [filterTrainee, setFilterTrainee] = useState(false);
    const [error, setError] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['equipment-items'],
        queryFn: async () => {
            const res = await api<{ items: EquipmentItem[] }>('/equipment-items');
            return res.items || [];
        },
    });

    const create = useMutation({
        mutationFn: async () => api<{ item: EquipmentItem }>('/equipment-items', { method: 'POST', body: await formToData(form) }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
            setForm(emptyForm);
            setError('');
        },
        onError: (e) => setError(describeError(e, 'Could not save item')),
    });

    const update = useMutation({
        mutationFn: async ({ id, payload }: { id: number; payload: Partial<EquipmentItem> }) => {
            const data = new FormData();
            data.append('_method', 'PATCH');
            Object.entries(payload).forEach(([key, value]) => {
                if (typeof value === 'boolean') data.append(key, value ? '1' : '0');
                else if (value !== undefined && value !== null) data.append(key, String(value));
            });

            return api<{ item: EquipmentItem }>(`/equipment-items/${id}`, { method: 'POST', body: data });
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
        },
    });

    const remove = useMutation({
        mutationFn: async (id: number) => api(`/equipment-items/${id}`, { method: 'DELETE' }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['equipment-items'] }),
        onError: (e) => setError(describeError(e, 'Could not delete item')),
    });

    const saveEdit = useMutation({
        mutationFn: async () => {
            if (editingId === null) return;
            const data = await formToData(editForm);
            data.append('_method', 'PATCH');

            return api<{ item: EquipmentItem }>(`/equipment-items/${editingId}`, { method: 'POST', body: data });
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['equipment-items'] });
            setEditingId(null);
            setError('');
        },
        onError: (e) => setError(describeError(e, 'Could not save item')),
    });

    const startEdit = (item: EquipmentItem) => {
        setEditingId(item.id);
        setEditForm({
            name: item.name,
            category: item.category || '',
            serial_number: item.serial_number || '',
            asset_tag: item.asset_tag || '',
            condition: item.condition || '',
            total_qty: String(item.total_qty),
            allowed_for_trainees: !!item.allowed_for_trainees,
            files: [],
        });
    };

    const categories = useMemo(
        () => Array.from(new Set((data || []).map((it) => (it.category || '').trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b)),
        [data],
    );

    const items = (data || []).filter((it) => (filterTrainee ? !!it.allowed_for_trainees : true));

    return (
        <div className="space-y-4">
            <PageHeader
                title="Equipment inventory"
                description="Add and edit equipment, quantities, condition, serial numbers, trainee eligibility and photos."
                actions={
                    <Button size="sm" variant="secondary" asChild>
                        <Link to="/equipment-labels">Print all QR labels</Link>
                    </Button>
                }
            />

            {error ? <div className="whitespace-pre-line rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-700">{error}</div> : null}

            <Card>
                <CardHeader>
                    <CardTitle>Add item</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <Input placeholder="Name *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        <CategorySelect value={form.category} options={categories} onChange={(category) => setForm({ ...form, category })} />
                        <Input placeholder="Serial number" value={form.serial_number} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} />
                        <Input placeholder="Asset tag" value={form.asset_tag} onChange={(e) => setForm({ ...form, asset_tag: e.target.value })} />
                        <Input placeholder="Condition" value={form.condition} onChange={(e) => setForm({ ...form, condition: e.target.value })} />
                        <Input type="number" min={0} placeholder="Quantity" value={form.total_qty} onChange={(e) => setForm({ ...form, total_qty: e.target.value })} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.allowed_for_trainees} onChange={(e) => setForm({ ...form, allowed_for_trainees: e.target.checked })} />
                        Allowed for trainees
                    </label>
                    <ProofUploader
                        label="Equipment images"
                        required={false}
                        helpText="Add as many photos as you need. Camera capture works on mobile."
                        files={form.files}
                        onChange={(files) => setForm({ ...form, files })}
                    />
                    <Button disabled={!form.name.trim() || create.isPending} onClick={() => create.mutate()}>
                        {create.isPending ? 'Saving…' : 'Add item'}
                    </Button>
                </CardContent>
            </Card>

            <div className="flex items-center gap-2 text-sm">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={filterTrainee} onChange={(e) => setFilterTrainee(e.target.checked)} />
                    Filter: allowed for trainees only
                </label>
            </div>

            {isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
            {!isLoading && items.length === 0 ? <EmptyState title="No equipment items yet" /> : null}

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                {items.map((item) => (
                    <Card key={item.id}>
                        <CardHeader className="flex-row items-start justify-between gap-2">
                            <div>
                                <CardTitle>{item.name}</CardTitle>
                                <p className="text-xs text-neutral-500">
                                    {[item.category, item.serial_number ? `SN ${item.serial_number}` : null, item.asset_tag ? `Tag ${item.asset_tag}` : null]
                                        .filter(Boolean)
                                        .join(' · ') || 'No category or identifiers'}
                                </p>
                            </div>
                            <Badge tone={item.allowed_for_trainees ? 'green' : 'neutral'}>{item.allowed_for_trainees ? 'Trainee allowed' : 'Staff only'}</Badge>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {editingId === item.id ? (
                                <div className="space-y-3">
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <Input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} placeholder="Name" />
                                        <CategorySelect value={editForm.category} options={categories} onChange={(category) => setEditForm({ ...editForm, category })} />
                                        <Input
                                            value={editForm.serial_number}
                                            disabled={!item.can_edit_serial}
                                            title={item.can_edit_serial ? undefined : 'Only an admin can change the serial number'}
                                            onChange={(e) => setEditForm({ ...editForm, serial_number: e.target.value })}
                                            placeholder={item.can_edit_serial ? 'Serial number' : 'Serial number (admin only)'}
                                        />
                                        <Input value={editForm.asset_tag} onChange={(e) => setEditForm({ ...editForm, asset_tag: e.target.value })} placeholder="Asset tag" />
                                        <Input value={editForm.condition} onChange={(e) => setEditForm({ ...editForm, condition: e.target.value })} placeholder="Condition" />
                                        <Input type="number" min={0} value={editForm.total_qty} onChange={(e) => setEditForm({ ...editForm, total_qty: e.target.value })} placeholder="Quantity" />
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={editForm.allowed_for_trainees}
                                            onChange={(e) => setEditForm({ ...editForm, allowed_for_trainees: e.target.checked })}
                                        />
                                        Allowed for trainees
                                    </label>
                                    <ProofUploader
                                        label="Add equipment images"
                                        required={false}
                                        files={editForm.files}
                                        onChange={(files) => setEditForm({ ...editForm, files })}
                                    />
                                    <div className="flex gap-2">
                                        <Button size="sm" disabled={saveEdit.isPending} onClick={() => saveEdit.mutate()}>
                                            {saveEdit.isPending ? 'Saving…' : 'Save changes'}
                                        </Button>
                                        <Button size="sm" variant="secondary" onClick={() => setEditingId(null)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="rounded bg-neutral-100 px-2 py-1 text-xs">{item.total_qty} in stock</span>
                                        <span className="text-xs text-neutral-500">Condition: {item.condition || '—'}</span>
                                        <label className="ml-auto flex items-center gap-1 text-xs">
                                            <input
                                                type="checkbox"
                                                checked={!!item.allowed_for_trainees}
                                                onChange={(e) => update.mutate({ id: item.id, payload: { allowed_for_trainees: e.target.checked } })}
                                            />
                                            Trainees
                                        </label>
                                    </div>
                                    {item.images.length > 0 ? (
                                        <div className="flex flex-wrap gap-2">
                                            {item.images.map((image) => (
                                                <a key={image.id} href={image.url || '#'} target="_blank" rel="noreferrer">
                                                    <img src={image.url || ''} alt={image.name || item.name} className="h-16 w-16 rounded-lg border object-cover" />
                                                </a>
                                            ))}
                                        </div>
                                    ) : item.photo_url ? (
                                        <img src={item.photo_url} alt={item.name} className="h-16 w-16 rounded-lg border object-cover" />
                                    ) : (
                                        <p className="text-xs text-neutral-400">No photos yet.</p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Button size="sm" variant="secondary" onClick={() => startEdit(item)}>
                                            Edit item
                                        </Button>
                                        <Button size="sm" variant="secondary" asChild>
                                            <Link to={`/equipment-labels?ids=${item.id}`}>QR label</Link>
                                        </Button>
                                        {item.can_delete ? (
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                disabled={remove.isPending}
                                                onClick={() => {
                                                    if (window.confirm(`Delete ${item.name}? This cannot be undone from the app.`)) remove.mutate(item.id);
                                                }}
                                            >
                                                Delete
                                            </Button>
                                        ) : null}
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}

