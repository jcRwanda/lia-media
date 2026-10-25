import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { api } from '@/lib/api';
import { useState } from 'react';

export default function MaterialRequestDetailPage() {
    const { id } = useParams();
    const qc = useQueryClient();
    const { data, isLoading } = useQuery({
        queryKey: ['material-request', id],
        queryFn: async () => {
            const res = await api<any>(`/material-requests/${id}`);
            return res.request;
        },
    });

    const [note, setNote] = useState('');
    const [issueItems, setIssueItems] = useState<any[]>([]);
    const [returnItems, setReturnItems] = useState<any[]>([]);

    const approve = useMutation({
        mutationFn: async () => {
            const items = (data?.items || []).map((it: any) => ({ id: it.id, qty_approved: it.qty_approved ?? it.qty_requested }));
            const res = await api<any>(`/material-requests/${id}/approve`, { method: 'POST', json: { note, items } });
            return res;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const reject = useMutation({
        mutationFn: async () => {
            const res = await api<any>(`/material-requests/${id}/reject`, { method: 'POST', json: { note } });
            return res;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const issue = useMutation({
        mutationFn: async () => {
            const formData = new FormData();
            issueItems.forEach((it, idx) => {
                formData.append(`items[${idx}][id]`, it.id);
                formData.append(`items[${idx}][qty_issued]`, it.qty_issued);
                formData.append(`items[${idx}][issue_condition]`, it.issue_condition || '');
                if (it.issue_proof) formData.append(`items[${idx}][issue_proof]`, it.issue_proof);
            });
            const res = await fetch(`/api/material-requests/${id}/issue`, { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const ret = useMutation({
        mutationFn: async () => {
            const formData = new FormData();
            returnItems.forEach((it, idx) => {
                formData.append(`items[${idx}][id]`, it.id);
                formData.append(`items[${idx}][qty_returned]`, it.qty_returned);
                formData.append(`items[${idx}][return_condition]`, it.return_condition || '');
                if (it.return_proof) formData.append(`items[${idx}][return_proof]`, it.return_proof);
            });
            const res = await fetch(`/api/material-requests/${id}/return`, { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    if (isLoading || !data) return <div className="p-4">Loading...</div>;

    return (
        <div className="p-4 space-y-4">
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">Request {data.reference_code}</h1>
                <span className="px-2 py-1 rounded bg-neutral-100 text-sm capitalize">{data.status} {data.is_overdue ? '(overdue)' : ''}</span>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div className="border rounded p-3 space-y-1">
                    <div className="font-medium">Requester</div>
                    <div>{data.full_name}</div>
                    <div>{data.email}</div>
                    <div>{data.phone}</div>
                    <div>Type: {data.requester_type}</div>
                    <div>Project: {data.project_name || '—'}</div>
                    <div>Cohort: {data.cohort?.name || data.program_cohort || '—'}</div>
                </div>
                <div className="border rounded p-3 space-y-1">
                    <div className="font-medium">Dates</div>
                    <div>Pick-up: {data.needed_from}</div>
                    <div>Return: {data.return_by}</div>
                    <div>Summary: {data.purpose_project_description}</div>
                    <div>Location: {[data.project_cell, data.project_sector, data.project_district, data.project_province].filter(Boolean).join(', ') || '—'}</div>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div className="border rounded p-3 space-y-1">
                    <div className="font-medium">Residential address</div>
                    <div>{[data.village_res, data.cell_res, data.sector_res, data.district_res, data.province_res].filter(Boolean).join(', ') || '—'}</div>
                    <div>Emergency: {data.emergency_contact_name} ({data.relationship || '—'})</div>
                    <div>{data.emergency_contact_phone}</div>
                </div>
                <div className="border rounded p-3 space-y-1">
                    <div className="font-medium">Documents (staff only)</div>
                    {data.id_document_url ? (
                        <a className="text-blue-600 underline" href={data.id_document_url} target="_blank" rel="noreferrer">View ID document</a>
                    ) : <div className="text-neutral-500">No ID uploaded</div>}
                    {data.signature_url ? (
                        <div>
                            <div>Signature {data.signature_date ? `(${data.signature_date})` : ''}</div>
                            <img src={data.signature_url} alt="Signature" className="mt-1 h-20 rounded border bg-white" />
                        </div>
                    ) : null}
                    <div>Consent: {data.consent_declaration ? 'Accepted' : 'Not accepted'}</div>
                </div>
            </div>

            <div className="border rounded overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-neutral-50">
                        <tr>
                            <th className="text-left p-2">Item</th>
                            <th className="text-left p-2">Requested</th>
                            <th className="text-left p-2">Approved</th>
                            <th className="text-left p-2">Issued</th>
                            <th className="text-left p-2">Returned</th>
                        </tr>
                    </thead>
                    <tbody>
                        {(data.items||[]).map((it: any) => (
                            <tr key={it.id} className="border-t">
                                <td className="p-2">{it.equipment_item?.name}</td>
                                <td className="p-2">{it.qty_requested}</td>
                                <td className="p-2">{it.qty_approved}</td>
                                <td className="p-2">{it.qty_issued}</td>
                                <td className="p-2">{it.qty_returned}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {data.status === 'submitted' && (
                <div className="border rounded p-3 space-y-2">
                    <div className="font-medium">Approve / Reject</div>
                    <textarea value={note} onChange={(e)=>setNote(e.target.value)} placeholder="Note" className="w-full border rounded p-2 text-sm" />
                    <div className="flex gap-2">
                        <button onClick={()=>approve.mutate()} className="px-3 py-1 bg-green-600 text-white rounded text-sm">Approve</button>
                        <button onClick={()=>reject.mutate()} className="px-3 py-1 bg-red-600 text-white rounded text-sm">Reject</button>
                    </div>
                </div>
            )}

            {data.status === 'approved' && (
                <div className="border rounded p-3 space-y-2">
                    <div className="font-medium">Issue (storekeeper) - photo proof required</div>
                    {(data.items||[]).map((it: any, idx: number) => (
                        <div key={it.id} className="grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
                            <div className="text-sm">{it.equipment_item?.name}</div>
                            <input type="number" placeholder="Qty issued" className="border rounded p-1 text-sm" onChange={(e)=>setIssueItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, qty_issued:e.target.value}; return n;})} />
                            <input type="text" placeholder="Condition" className="border rounded p-1 text-sm" onChange={(e)=>setIssueItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, issue_condition:e.target.value}; return n;})} />
                            <input type="file" accept="image/*" capture="environment" className="text-sm" onChange={(e)=>setIssueItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, issue_proof:e.target.files?.[0]}; return n;})} required />
                        </div>
                    ))}
                    <button onClick={()=>issue.mutate()} className="px-3 py-1 bg-blue-600 text-white rounded text-sm">Issue</button>
                </div>
            )}

            {(data.status === 'issued' || data.status === 'approved') && (
                <div className="border rounded p-3 space-y-2">
                    <div className="font-medium">Return (storekeeper) - photo proof required, partial returns supported</div>
                    {(data.items||[]).map((it: any, idx: number) => (
                        <div key={it.id} className="grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
                            <div className="text-sm">{it.equipment_item?.name}</div>
                            <input type="number" placeholder="Qty returned" className="border rounded p-1 text-sm" onChange={(e)=>setReturnItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, qty_returned:e.target.value}; return n;})} />
                            <input type="text" placeholder="Condition" className="border rounded p-1 text-sm" onChange={(e)=>setReturnItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, return_condition:e.target.value}; return n;})} />
                            <input type="file" accept="image/*" capture="environment" className="text-sm" onChange={(e)=>setReturnItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, return_proof:e.target.files?.[0]}; return n;})} required />
                        </div>
                    ))}
                    <button onClick={()=>ret.mutate()} className="px-3 py-1 bg-amber-600 text-white rounded text-sm">Record Return</button>
                </div>
            )}

            {data.review_note && (
                <div className="border rounded p-3 text-sm">
                    <div className="font-medium">Review note</div>
                    <div>{data.review_note}</div>
                </div>
            )}
        </div>
    );
}
