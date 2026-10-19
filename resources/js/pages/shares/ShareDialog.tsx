import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Share2, X } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CopyField } from '@/components/ui/copy';
import { Field, Input, Select, Textarea } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';

const schema = z.object({
    mode: z.enum(['public', 'access_code', 'restricted']),
    title: z.string().max(190).optional(),
    message: z.string().max(2000).optional(),
    access_code: z.string().max(40).optional().refine((v) => !v || v.trim().length >= 6, 'At least 6 characters (or leave blank to generate)'),
    expires_in_days: z.string(),
    max_uses: z.string().optional(),
    allow_download: z.boolean(),
    include_originals: z.boolean(),
    recipients: z.string().optional(),
}).superRefine((v, ctx) => {
    if (v.mode === 'restricted') {
        const emails = (v.recipients || '').split(/[\s,;]+/).filter(Boolean);
        if (!emails.length) ctx.addIssue({ code: 'custom', path: ['recipients'], message: 'Add at least one email' });
        if (emails.some((e) => !z.string().email().safeParse(e).success)) ctx.addIssue({ code: 'custom', path: ['recipients'], message: 'One of the emails is not valid' });
    }
});
type Form = z.infer<typeof schema>;

interface Created { url: string | null; access_code: string | null; recipients: { email: string; url: string }[] }

const modeHelp: Record<Form['mode'], string> = {
    public: 'Anyone with the link can view previews.',
    access_code: 'Visitors also need a code. Wrong codes are rate-limited; a correct code unlocks access for 2 hours.',
    restricted: 'Each recipient gets a personal link you can revoke individually. Can include originals.',
};

export default function ShareDialog({ target }: { target: { type: 'album' | 'asset'; uuid: string; title: string } }) {
    const [open, setOpen] = useState(false);
    const [created, setCreated] = useState<Created | null>(null);
    const qc = useQueryClient();
    const form = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { mode: 'access_code', title: target.title, message: '', access_code: '', expires_in_days: '30', max_uses: '', allow_download: true, include_originals: false, recipients: '' } });
    const mode = form.watch('mode');
    const create = useMutation({
        mutationFn: (v: Form) => api<Created>('/shares', {
            method: 'POST',
            json: {
                target_type: target.type, target_uuid: target.uuid, mode: v.mode, title: v.title || null, message: v.message || null,
                access_code: v.mode === 'access_code' ? v.access_code || null : null, allow_download: v.allow_download, include_originals: v.mode === 'restricted' && v.include_originals,
                expires_at: v.expires_in_days === 'never' ? null : new Date(Date.now() + Number(v.expires_in_days) * 86400000).toISOString(),
                max_uses: v.max_uses ? Number(v.max_uses) : null,
                recipients: v.mode === 'restricted' ? (v.recipients || '').split(/[\s,;]+/).filter(Boolean) : [],
            },
        }),
        onSuccess: (r) => { setCreated(r); qc.invalidateQueries({ queryKey: ['shares'] }); },
        onError: (e) => Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError((k.startsWith('recipients') ? 'recipients' : k) as keyof Form, { message: m })),
    });
    const close = () => { setOpen(false); setCreated(null); form.reset(); };
    const e = form.formState.errors;
    return (
        <>
            <Button variant="secondary" onClick={() => setOpen(true)}><Share2 className="h-4 w-4" />Share</Button>
            {open ? (
                <div className="fixed inset-0 z-40 grid place-items-end bg-black/30 sm:place-items-center" onClick={close}>
                    <Card className="max-h-[92vh] w-full overflow-y-auto rounded-b-none p-5 sm:max-w-lg sm:rounded-xl" onClick={(ev) => ev.stopPropagation()} role="dialog" aria-label="Create share link">
                        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Share “{target.title}”</h2><button aria-label="Close" onClick={close}><X className="h-5 w-5" /></button></div>
                        {created ? (
                            <div className="space-y-3">
                                <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">Copy these now. For security only a fingerprint is stored, so they can’t be shown again (you can rotate the link later).</p>
                                {created.url ? <CopyField label="Link" value={created.url} /> : null}
                                {created.access_code ? <CopyField label="Access code (send separately)" value={created.access_code} /> : null}
                                {created.recipients.map((r) => <CopyField key={r.email} label={`Personal link for ${r.email}`} value={r.url} />)}
                                <p className="text-xs text-neutral-500">Only approved or published items appear. Revoking stops access at once; images already opened stay viewable for up to 5 minutes.</p>
                                <Button onClick={close}>Done</Button>
                            </div>
                        ) : (
                            <form className="space-y-3" onSubmit={form.handleSubmit((v) => create.mutate(v))} noValidate>
                                <Field label="Access"><Select {...form.register('mode')}><option value="access_code">Link + access code</option><option value="public">Public link</option><option value="restricted">Named recipients only</option></Select></Field>
                                <p className="-mt-1 text-xs text-neutral-500">{modeHelp[mode]}</p>
                                <Field label="Title shown to visitors" error={e.title?.message}><Input {...form.register('title')} /></Field>
                                <Field label="Message (optional)" error={e.message?.message}><Textarea rows={2} {...form.register('message')} /></Field>
                                {mode === 'access_code' ? <Field label="Access code (blank = generate)" error={e.access_code?.message}><Input {...form.register('access_code')} autoComplete="off" /></Field> : null}
                                {mode === 'restricted' ? <Field label="Recipient emails" error={e.recipients?.message}><Textarea rows={2} placeholder="press@example.com, partner@example.org" {...form.register('recipients')} /></Field> : null}
                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Expires"><Select {...form.register('expires_in_days')}><option value="1">in 1 day</option><option value="7">in 7 days</option><option value="30">in 30 days</option><option value="90">in 90 days</option><option value="never">never</option></Select></Field>
                                    <Field label="Max visitors" error={e.max_uses?.message}><Input type="number" min={1} placeholder="unlimited" {...form.register('max_uses')} /></Field>
                                </div>
                                <label className="flex items-center gap-2 text-sm"><input type="checkbox" {...form.register('allow_download')} />Allow downloads</label>
                                {mode === 'restricted' ? <label className="flex items-center gap-2 text-sm"><input type="checkbox" {...form.register('include_originals')} />Downloads are full-resolution originals</label> : null}
                                {create.isError && !Object.keys(fieldErrors(create.error)).length ? <p role="alert" className="text-sm text-lia-700">{(create.error as Error).message}</p> : null}
                                <Button type="submit" disabled={create.isPending}>Create link</Button>
                            </form>
                        )}
                    </Card>
                </div>
            ) : null}
        </>
    );
}
