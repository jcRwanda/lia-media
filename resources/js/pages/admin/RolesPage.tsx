import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Badge, Card, PageHeader } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';

interface RolePerms {
    [group: string]: {
        label: string;
        permissions: { [perm: string]: string };
    };
}

interface Role {
    id: number;
    name: string;
    slug: string;
    is_system: boolean;
    is_admin: boolean;
    permissions: string[];
}

const schema = z.object({ name: z.string().min(2) });

export default function RolesPage() {
    const qc = useQueryClient();
    const q = useQuery({ queryKey: ['roles'], queryFn: () => api<{ data: Role[]; permissions: RolePerms }>('/roles') });
    const [editing, setEditing] = useState<Role | null>(null);
    const [selectedPerms, setSelectedPerms] = useState<string[]>([]);
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { name: '' } });
    const create = useMutation({
        mutationFn: (v: z.infer<typeof schema>) => api('/roles', { method: 'POST', json: { name: v.name, permissions: selectedPerms } }),
        onSuccess: () => {
            form.reset();
            setSelectedPerms([]);
            qc.invalidateQueries({ queryKey: ['roles'] });
        },
        onError: (e) => Object.entries(fieldErrors(e)).forEach(([k, m]) => form.setError(k as never, { message: m })),
    });
    const update = useMutation({
        mutationFn: ({ id, ...v }: any) => api(`/roles/${id}`, { method: 'PATCH', json: v }),
        onSuccess: () => {
            setEditing(null);
            setSelectedPerms([]);
            qc.invalidateQueries({ queryKey: ['roles'] });
        },
        onError: (e) => alert(Object.values(fieldErrors(e))[0] || (e as Error).message),
    });
    const remove = useMutation({
        mutationFn: (id: number) => api(`/roles/${id}`, { method: 'DELETE' }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['roles'] }),
        onError: (e) => alert(Object.values(fieldErrors(e))[0] || (e as Error).message),
    });

    const roles = q.data?.data ?? [];
    const groups = q.data?.permissions ?? {};
    const e = form.formState.errors;

    const togglePerm = (perm: string) => {
        setSelectedPerms((prev) => (prev.includes(perm) ? prev.filter((p) => p !== perm) : [...prev, perm]));
    };

    const startEdit = (role: Role) => {
        setEditing(role);
        setSelectedPerms(role.permissions || []);
        form.reset({ name: role.name });
    };

    return (
        <>
            <PageHeader title="Roles" description="Manage roles and permissions." />
            <div className="grid gap-5 lg:grid-cols-3">
                <Card className="divide-y divide-neutral-100 lg:col-span-2">
                    {roles.map((role) => (
                        <div key={role.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">
                                    {role.name}{' '}
                                    {role.is_admin ? <Badge tone="blue">admin</Badge> : null}{' '}
                                    {role.is_system ? <Badge tone="neutral">system</Badge> : null}
                                </div>
                                <div className="text-xs text-neutral-500">{(role.permissions || []).length} permissions</div>
                            </div>
                            {!role.is_admin ? (
                                <>
                                    <Button size="sm" variant="secondary" onClick={() => startEdit(role)}>Edit</Button>
                                    <Button size="sm" variant="destructive" disabled={remove.isPending} onClick={() => {
                                        if (confirm(`Delete role ${role.name}?`)) remove.mutate(role.id);
                                    }}>Delete</Button>
                                </>
                            ) : (
                                <span className="text-xs text-neutral-400">Protected</span>
                            )}
                        </div>
                    ))}
                </Card>
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">{editing ? `Edit role: ${editing.name}` : 'Create role'}</h2>
                    <form className="mt-3 space-y-3" onSubmit={form.handleSubmit((v) => {
                        if (editing) {
                            update.mutate({ id: editing.id, name: v.name, permissions: selectedPerms });
                        } else {
                            create.mutate(v);
                        }
                    })} noValidate>
                        <Field label="Name" error={e.name?.message}><Input {...form.register('name')} disabled={editing?.is_admin} /></Field>
                        <div className="space-y-3">
                            {Object.entries(groups).map(([key, group]: [string, any]) => (
                                <div key={key} className="rounded-lg border border-neutral-200 p-2">
                                    <div className="mb-2 text-xs font-medium">{group.label}</div>
                                    <div className="space-y-1">
                                        {Object.entries(group.permissions).map(([perm, label]: [string, any]) => (
                                            <label key={perm} className="flex items-center gap-2 text-xs">
                                                <input type="checkbox" checked={selectedPerms.includes(perm)} onChange={() => togglePerm(perm)} disabled={editing?.is_admin} />
                                                {label}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={create.isPending || update.isPending || editing?.is_admin}>
                                {editing ? 'Save changes' : 'Create role'}
                            </Button>
                            {editing ? <Button type="button" variant="secondary" onClick={() => { setEditing(null); setSelectedPerms([]); form.reset({ name: '' }); }}>Cancel</Button> : null}
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}
