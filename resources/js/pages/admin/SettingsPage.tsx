import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Badge, Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';
import { formatBytes } from '@/lib/utils';

const schema = z.object({
    organisation_name: z.string().min(1).max(120),
    default_share_days: z.coerce.number().int().min(1).max(365),
    require_consent_for_publish: z.boolean(),
    storage_price_per_gb_month: z.coerce.number().min(0).max(1),
    storage_free_gb: z.coerce.number().min(0),
    monthly_budget_usd: z.coerce.number().min(0),
});
type FormIn = z.input<typeof schema>;
type FormOut = z.output<typeof schema>;
type Payload = { settings: FormOut; system: { storage_driver: string; bucket: string | null; storage_configured: boolean; backup_driver: string; backup_enabled: boolean; url_ttl: Record<string, number>; multipart_threshold: number; max_file_size: number; queue: string } };

export default function SettingsPage() {
    const qc = useQueryClient();
    const q = useQuery({ queryKey: ['settings'], queryFn: () => api<Payload>('/settings') });
    const form = useForm<FormIn, unknown, FormOut>({ resolver: zodResolver(schema) });
    const [saved, setSaved] = useState('');
    useEffect(() => { if (q.data) form.reset(q.data.settings); }, [q.data]); // eslint-disable-line react-hooks/exhaustive-deps
    const save = useMutation({
        mutationFn: (v: FormOut) => api<Payload>('/settings', { method: 'PATCH', json: v }),
        onSuccess: (r) => { qc.setQueryData(['settings'], r); setSaved('Saved'); setTimeout(() => setSaved(''), 2500); },
        onError: (e) => Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError(k as keyof FormIn, { message: m })),
    });
    const s = q.data?.system;
    const e = form.formState.errors;
    return (
        <>
            <PageHeader title="Settings" />
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Archive</CardTitle></CardHeader>
                    <CardContent>
                        <form className="space-y-3" onSubmit={form.handleSubmit((v) => save.mutate(v))} noValidate>
                            <Field label="Organisation name" error={e.organisation_name?.message}><Input {...form.register('organisation_name')} /></Field>
                            <Field label="Share links expire after (days, default)" error={e.default_share_days?.message}><Input type="number" {...form.register('default_share_days')} /></Field>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" {...form.register('require_consent_for_publish')} /> Block publishing when consent is missing or withdrawn</label>
                            <div className="grid grid-cols-3 gap-2">
                                <Field label="$ / GB-month" error={e.storage_price_per_gb_month?.message}><Input type="number" step="0.001" {...form.register('storage_price_per_gb_month')} /></Field>
                                <Field label="Free GB" error={e.storage_free_gb?.message}><Input type="number" {...form.register('storage_free_gb')} /></Field>
                                <Field label="Budget $ / month" error={e.monthly_budget_usd?.message}><Input type="number" step="0.01" {...form.register('monthly_budget_usd')} /></Field>
                            </div>
                            <div className="flex items-center gap-3"><Button type="submit" disabled={save.isPending}>Save settings</Button>{saved ? <span role="status" className="text-xs text-emerald-700">{saved}</span> : null}</div>
                        </form>
                    </CardContent>
                </Card>
                {s ? (
                    <Card>
                        <CardHeader><CardTitle>System (from server environment)</CardTitle></CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div>Storage: <b>{s.storage_driver}</b> {s.bucket ? `· bucket ${s.bucket}` : ''} <Badge tone={s.storage_configured ? 'green' : 'amber'}>{s.storage_configured ? 'configured' : 'not configured'}</Badge></div>
                            <div>Backups: <b>{s.backup_driver}</b> <Badge tone={s.backup_enabled ? 'green' : 'amber'}>{s.backup_enabled ? 'on' : 'off — R2 only'}</Badge></div>
                            <div>Multipart above {formatBytes(s.multipart_threshold)} · max file {formatBytes(s.max_file_size)}</div>
                            <div>Link lifetimes: {Object.entries(s.url_ttl ?? {}).map(([k, v]) => `${k} ${v}s`).join(' · ')}</div>
                            <div>Queue: {s.queue} (run by cron <code>schedule:run</code>)</div>
                            <p className="text-xs text-neutral-500">Keys and bucket are set in the server .env and are never shown here.</p>
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </>
    );
}
