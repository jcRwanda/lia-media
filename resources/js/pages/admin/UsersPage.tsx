import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Badge, Card, PageHeader } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';
import type { User } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const roles = ['admin', 'multimedia', 'communications', 'management'] as const;
const schema = z.object({ name: z.string().min(2), email: z.string().email(), role: z.enum(roles) });

/** The admin-created account is unlocked by the welcome email, not by a password typed here. */
export default function UsersPage() {
    const qc = useQueryClient();
    const users = useQuery({ queryKey: ['users'], queryFn: () => api<{ data: User[] }>('/users') });
    const [created, setCreated] = useState<boolean | null>(null);
    const [resent, setResent] = useState<{ id: number; sent: boolean; error?: string } | null>(null);
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { name: '', email: '', role: 'multimedia' } });
    const create = useMutation({
        mutationFn: (v: z.infer<typeof schema>) => api<{ user: User; welcome_email_sent: boolean }>('/users', { method: 'POST', json: v }),
        onSuccess: (r) => { form.reset(); setCreated(r.welcome_email_sent); qc.invalidateQueries({ queryKey: ['users'] }); },
        onError: (e) => Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError(k as never, { message: m })),
    });
    const update = useMutation({
        mutationFn: ({ id, ...v }: { id: number; role?: string; status?: string }) => api(`/users/${id}`, { method: 'PATCH', json: v }),
        onSettled: () => qc.invalidateQueries({ queryKey: ['users'] }),
        onError: (e) => alert(Object.values(fieldErrors(e))[0] || (e as Error).message),
    });
    const resend = useMutation({
        mutationFn: (u: User) => api<{ user: User; welcome_email_sent: boolean }>(`/users/${u.id}/resend-welcome`, { method: 'POST' }),
        onSuccess: (r, u) => { setResent({ id: u.id, sent: r.welcome_email_sent }); qc.invalidateQueries({ queryKey: ['users'] }); },
        onError: (e, u) => setResent({ id: u.id, sent: false, error: (e as Error).message }),
    });
    const e = form.formState.errors;
    return (
        <>
            <PageHeader title="Users & roles" description="Staff accounts for the archive. Separate from the learning portal." />
            <div className="grid gap-5 lg:grid-cols-3">
                <Card className="divide-y divide-neutral-100 lg:col-span-2">
                    {(users.data?.data ?? []).map((u) => (
                        <div key={u.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">
                                    {u.name}{' '}
                                    {u.status === 'disabled' ? <Badge tone="red">disabled</Badge> : null}{' '}
                                    {u.must_change_password ? <Badge tone="amber">{u.last_login_at ? 'Must change password' : 'Not logged in yet'}</Badge> : null}
                                </div>
                                <div className="truncate text-xs text-neutral-500">{u.email} · last login {formatDate(u.last_login_at)}</div>
                                {resent?.id === u.id ? (
                                    <div className={resent.sent ? 'text-xs text-emerald-700' : 'text-xs text-amber-700'}>
                                        {resent.sent ? 'A new temporary password was emailed to them.' : `The welcome email could not be sent${resent.error ? `: ${resent.error}` : ' — try again.'}`}
                                    </div>
                                ) : null}
                            </div>
                            <Select aria-label={`Role for ${u.name}`} className="h-8 w-40" value={u.role} onChange={(ev) => update.mutate({ id: u.id, role: ev.target.value })}>{roles.map((r) => <option key={r}>{r}</option>)}</Select>
                            <Button size="sm" variant={u.status === 'active' ? 'destructive' : 'secondary'} onClick={() => update.mutate({ id: u.id, status: u.status === 'active' ? 'disabled' : 'active' })}>{u.status === 'active' ? 'Disable' : 'Enable'}</Button>
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={resend.isPending}
                                onClick={() => {
                                    if (u.must_change_password || window.confirm(`Send ${u.name} a new temporary password? Their current password stops working and they must set a new one on next sign-in.`)) {
                                        resend.mutate(u);
                                    }
                                }}
                            >
                                {resend.isPending && resend.variables?.id === u.id ? 'Sending…' : 'Resend welcome email'}
                            </Button>
                        </div>
                    ))}
                </Card>
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">Add user</h2>
                    <p className="mt-1 text-xs text-neutral-500">A temporary password is generated and emailed to them; they set their own on first sign-in.</p>
                    <form className="mt-3 space-y-3" onSubmit={form.handleSubmit((v) => create.mutate(v))} noValidate>
                        <Field label="Name" error={e.name?.message}><Input {...form.register('name')} /></Field>
                        <Field label="Email" error={e.email?.message}><Input type="email" {...form.register('email')} /></Field>
                        <Field label="Role" error={e.role?.message}><Select {...form.register('role')}>{roles.map((r) => <option key={r}>{r}</option>)}</Select></Field>
                        {created !== null ? (
                            <p role="status" className={created ? 'rounded-lg bg-emerald-50 p-2 text-xs text-emerald-700' : 'rounded-lg bg-amber-50 p-2 text-xs text-amber-800'}>
                                {created ? 'User created — the welcome email with their temporary password is on its way.' : 'User created, but the welcome email could not be sent. Use “Resend welcome email” once mail is working.'}
                            </p>
                        ) : null}
                        <Button type="submit" disabled={create.isPending}>Create user</Button>
                    </form>
                </Card>
            </div>
        </>
    );
}
