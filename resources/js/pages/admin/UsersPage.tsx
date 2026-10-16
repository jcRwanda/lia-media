import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Badge, Card, PageHeader } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';
import type { User } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const roles = ['admin', 'multimedia', 'communications', 'management'] as const;
const schema = z.object({ name: z.string().min(2), email: z.string().email(), role: z.enum(roles), password: z.string().min(12, 'At least 12 characters') });

export default function UsersPage() {
    const qc = useQueryClient();
    const users = useQuery({ queryKey: ['users'], queryFn: () => api<{ data: User[] }>('/users') });
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { name: '', email: '', role: 'multimedia', password: '' } });
    const create = useMutation({
        mutationFn: (v: z.infer<typeof schema>) => api('/users', { method: 'POST', json: v }),
        onSuccess: () => { form.reset(); qc.invalidateQueries({ queryKey: ['users'] }); },
        onError: (e) => Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError(k as never, { message: m })),
    });
    const update = useMutation({
        mutationFn: ({ id, ...v }: { id: number; role?: string; status?: string }) => api(`/users/${id}`, { method: 'PATCH', json: v }),
        onSettled: () => qc.invalidateQueries({ queryKey: ['users'] }),
        onError: (e) => alert(Object.values(fieldErrors(e))[0] || (e as Error).message),
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
                                <div className="font-medium">{u.name} {u.status === 'disabled' ? <Badge tone="red">disabled</Badge> : null}</div>
                                <div className="truncate text-xs text-neutral-500">{u.email} · last login {formatDate(u.last_login_at)}</div>
                            </div>
                            <Select aria-label={`Role for ${u.name}`} className="h-8 w-40" value={u.role} onChange={(ev) => update.mutate({ id: u.id, role: ev.target.value })}>{roles.map((r) => <option key={r}>{r}</option>)}</Select>
                            <Button size="sm" variant={u.status === 'active' ? 'destructive' : 'secondary'} onClick={() => update.mutate({ id: u.id, status: u.status === 'active' ? 'disabled' : 'active' })}>{u.status === 'active' ? 'Disable' : 'Enable'}</Button>
                        </div>
                    ))}
                </Card>
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">Add user</h2>
                    <form className="mt-3 space-y-3" onSubmit={form.handleSubmit((v) => create.mutate(v))} noValidate>
                        <Field label="Name" error={e.name?.message}><Input {...form.register('name')} /></Field>
                        <Field label="Email" error={e.email?.message}><Input type="email" {...form.register('email')} /></Field>
                        <Field label="Role" error={e.role?.message}><Select {...form.register('role')}>{roles.map((r) => <option key={r}>{r}</option>)}</Select></Field>
                        <Field label="Temporary password" error={e.password?.message}><Input type="password" autoComplete="new-password" {...form.register('password')} /></Field>
                        <Button type="submit" disabled={create.isPending}>Create user</Button>
                    </form>
                </Card>
            </div>
        </>
    );
}
