import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { api } from '@/lib/api';
import { buildMaterialRequestWhatsappLink, formatDate } from '@/lib/utils';
import { ProofUploader } from '@/components/ProofUploader';
import { useState } from 'react';
import { MessageCircle } from 'lucide-react';

const EMPTY_FILES: File[] = [];

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
                if (it.notes) formData.append(`items[${idx}][note]`, it.notes);
                (it.issue_proofs || []).forEach((file: File, fileIdx: number) => {
                    formData.append(`items[${idx}][issue_proofs][${fileIdx}]`, file);
                });
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
                if (it.notes) formData.append(`items[${idx}][note]`, it.notes);
                (it.return_proofs || []).forEach((file: File, fileIdx: number) => {
                    formData.append(`items[${idx}][return_proofs][${fileIdx}]`, file);
                });
            });
            const res = await fetch(`/api/material-requests/${id}/return`, { method: 'POST', body: formData, credentials: 'same-origin' });
            return res.json();
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const acceptFinal = useMutation({
        mutationFn: async () => {
            const res = await api<any>(`/material-requests/${id}/final-work/accept`, { method: 'POST', json: { note } });
            return res;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const requestChanges = useMutation({
        mutationFn: async () => {
            const res = await api<any>(`/material-requests/${id}/final-work/request-changes`, { method: 'POST', json: { note } });
            return res;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['material-request', id] }),
    });

    const [decisionError, setDecisionError] = useState('');
    const decide = useMutation({
        mutationFn: async (action: 'reject' | 'request-changes') => {
            if (!note.trim()) throw new Error(action === 'reject' ? 'A reason is required to reject.' : 'Tell the requester what to change.');
            return api<any>(`/material-requests/${id}/${action}`, { method: 'POST', json: { note } });
        },
        onMutate: () => setDecisionError(''),
        onError: (e: any) => setDecisionError(e?.message || 'Failed'),
        onSuccess: () => { setNote(''); qc.invalidateQueries({ queryKey: ['material-request', id] }); },
    });

    if (isLoading || !data) return <div className="p-4">Loading...</div>;

    return (
        <div className="p-4 space-y-4">
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">Request {data.reference_code}</h1>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="px-2 py-1 rounded bg-lia-50 text-lia-700">{data.source_label || data.source || 'Direct/Hosted form'}</span>
                    <span className="px-2 py-1 rounded bg-neutral-100 capitalize">{data.status} {data.is_overdue ? '(overdue)' : ''}</span>
                    {['approved', 'issued', 'returned', 'closed'].includes(data.status) ? (
                        (() => {
                            const url = data.whatsapp_url || buildMaterialRequestWhatsappLink(data);
                            return url ? (
                                <a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded bg-emerald-600 px-3 py-1 text-white">
                                    <MessageCircle className="h-4 w-4" /> Send on WhatsApp
                                </a>
                            ) : null;
                        })()
                    ) : null}
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div className="border rounded p-3 space-y-1">
                    <div className="font-medium">Requester</div>
                    <div>{data.full_name}</div>
                    <div>{data.email}</div>
                    <div>{data.phone}</div>
                    <div>Type: {data.requester_type}</div>
                    <div>Source: {data.source_label || data.source || 'Direct/Hosted form'}</div>
                    {data.source_detail && <div>Source detail: {data.source_detail}</div>}
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
                            <th className="text-left p-2">Proofs</th>
                        </tr>
                    </thead>
                    <tbody>
                        {(data.items||[]).map((it: any) => (
                            <tr key={it.id} className="border-t">
                                <td className="p-2">
                                    <div>{it.equipment_item?.name}</div>
                                    {it.issued_at ? <div className="text-[11px] text-neutral-500">Handed out {formatDate(it.issued_at)}{it.issued_by ? ` by ${it.issued_by}` : ''}</div> : null}
                                    {it.returned_at ? <div className="text-[11px] text-neutral-500">Checked in {formatDate(it.returned_at)}{it.received_by ? ` by ${it.received_by}` : ''}</div> : null}
                                    {(it.checks || []).map((c: any) => (
                                        <div key={c.id} className={`text-[11px] ${c.flagged ? 'text-amber-700' : 'text-green-700'}`}>
                                            {c.flagged ? '⚠️' : '✓'} {c.type === 'pickup_confirm' ? 'Borrower confirmed pickup (QR' + (c.verified_via ? `, ${c.verified_via}` : '') + ')' : c.type === 'issue_check' ? 'Hand-out QR check' : 'Return QR check'}
                                            {' '}{formatDate(c.created_at)}{c.actor ? ` by ${c.actor}` : ''}
                                            {c.serial_matches === false ? ' · serial mismatch' : c.serial_matches ? ' · serial OK' : ''}
                                            {c.flag_reason ? ` · ${c.flag_reason}` : ''}{c.notes ? ` · “${c.notes}”` : ''}
                                        </div>
                                    ))}
                                </td>
                                <td className="p-2">{it.qty_requested}</td>
                                <td className="p-2">{it.qty_approved}</td>
                                <td className="p-2">{it.qty_issued}</td>
                                <td className="p-2">{it.qty_returned}</td>
                                <td className="p-2">
                                    <div className="flex flex-wrap gap-1">
                                        {[...(it.issue_proof_urls || []), ...(it.return_proof_urls || [])].map((url: string) => (
                                            <a key={url} href={url} target="_blank" rel="noreferrer">
                                                <img src={url} alt="Proof" className="h-10 w-10 rounded border object-cover" />
                                            </a>
                                        ))}
                                        {(!it.issue_proof_urls?.length && !it.return_proof_urls?.length) ? <span className="text-xs text-neutral-400">—</span> : null}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {(data.status === 'submitted' || data.status === 'changes_requested') && (
                <div className="border rounded p-3 space-y-2">
                    <div className="font-medium">Admin decision</div>
                    {data.status === 'changes_requested' && <div className="text-sm text-orange-700">Waiting for the requester to edit and resubmit. You can still reject it.</div>}
                    <textarea value={note} onChange={(e)=>setNote(e.target.value)} placeholder="Note (optional to approve; required to reject or request changes)" className="w-full border rounded p-2 text-sm" />
                    {decisionError && <div className="text-sm text-red-600">{decisionError}</div>}
                    <div className="flex flex-wrap gap-2">
                        {data.status === 'submitted' && <button onClick={()=>approve.mutate()} className="px-3 py-1 bg-green-600 text-white rounded text-sm">Approve</button>}
                        {data.status === 'submitted' && <button onClick={()=>decide.mutate('request-changes')} className="px-3 py-1 bg-orange-500 text-white rounded text-sm">Request changes</button>}
                        <button onClick={()=>decide.mutate('reject')} className="px-3 py-1 bg-red-600 text-white rounded text-sm">Reject</button>
                    </div>
                    {data.decision?.status_url && <div className="text-xs text-neutral-500">Requester status page: <a className="underline" href={data.decision.status_url} target="_blank" rel="noreferrer">{data.decision.status_url}</a></div>}
                </div>
            )}

            {Array.isArray(data.status_history) && data.status_history.length > 0 && (
                <div className="border rounded p-3">
                    <div className="font-medium mb-2">Status history</div>
                    <ul className="space-y-1 text-sm">
                        {data.status_history.map((h: any, i: number) => (
                            <li key={i} className="border-t pt-1">
                                <span className="text-neutral-500">{h.created_at ? new Date(h.created_at).toLocaleString() : ''}</span>{' '}
                                <b className="capitalize">{String(h.to_status).replace('_', ' ')}</b>
                                {h.from_status ? <span className="text-neutral-500"> (from {String(h.from_status).replace('_', ' ')})</span> : null}
                                <span className="text-neutral-500"> by {h.actor}</span>
                                {h.note && <div className="text-neutral-700">{h.note}</div>}
                            </li>
                        ))}
                    </ul>
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
                            <div className="sm:col-span-2">
                                <ProofUploader
                                    label="Hand-out photo proof"
                                    files={issueItems[idx]?.issue_proofs || EMPTY_FILES}
                                    onChange={(files)=>setIssueItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, issue_proofs:files}; return n;})}
                                />
                            </div>
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
                            <div className="sm:col-span-2">
                                <ProofUploader
                                    label="Check-in photo proof"
                                    files={returnItems[idx]?.return_proofs || EMPTY_FILES}
                                    onChange={(files)=>setReturnItems(prev=>{const n=[...prev]; n[idx]={...n[idx], id:it.id, return_proofs:files}; return n;})}
                                />
                            </div>
                        </div>
                    ))}
                    <button onClick={()=>ret.mutate()} className="px-3 py-1 bg-amber-600 text-white rounded text-sm">Record Return</button>
                </div>
            )}

            {data.requester_type === 'trainee' && (
                <div className="border rounded p-3 space-y-2">
                    <div className="flex items-center justify-between">
                        <div className="font-medium">Final work</div>
                        <span className="px-2 py-1 rounded bg-neutral-100 capitalize">{data.final_work_status || '—'}</span>
                    </div>
                    {(data.final_works || []).length > 0 ? (
                        <ul className="space-y-1 text-sm">
                            {(data.final_works || []).map((fw: any) => (
                                <li key={fw.id} className="flex items-center justify-between border-t pt-1">
                                    <div>
                                        <div>{fw.file_name || 'Final work'}</div>
                                        {fw.uploaded_at && <div className="text-neutral-500 text-xs">{fw.uploaded_at}</div>}
                                    </div>
                                    {fw.download_url ? (
                                        <a className="text-blue-600 underline" href={fw.download_url} target="_blank" rel="noreferrer">Download</a>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="text-sm text-neutral-500">No final work uploaded yet.</div>
                    )}
                    {data.final_work_submitted_at && (
                        <div className="text-xs text-neutral-500">Submitted: {data.final_work_submitted_at}</div>
                    )}
                    {data.final_work_reviewed_at && (
                        <div className="text-xs text-neutral-500">Reviewed: {data.final_work_reviewed_at}{data.final_work_reviewed_by ? ` by ${data.final_work_reviewed_by}` : ''}</div>
                    )}
                    {data.final_work_review_note && (
                        <div className="text-sm border-t pt-2">
                            <div className="font-medium">Review note</div>
                            <div>{data.final_work_review_note}</div>
                        </div>
                    )}
                    {(data.final_work_status === 'submitted' || data.final_work_status === 'needs_changes') && (
                        <div className="space-y-2 border-t pt-2">
                            <textarea value={note} onChange={(e)=>setNote(e.target.value)} placeholder="Note for trainee" className="w-full border rounded p-2 text-sm" />
                            <div className="flex gap-2">
                                <button onClick={()=>acceptFinal.mutate()} className="px-3 py-1 bg-green-600 text-white rounded text-sm">Accept final work</button>
                                <button onClick={()=>requestChanges.mutate()} className="px-3 py-1 bg-amber-600 text-white rounded text-sm">Request changes</button>
                            </div>
                        </div>
                    )}
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
