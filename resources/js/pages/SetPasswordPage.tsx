import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { KeyRound } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { Navigate, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { Brand } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';
import type { User } from '@/lib/types';

/** Mirrors the backend rule on POST /auth/change-password: at least 10 characters, confirmed. */
const schema = z
    .object({
        password: z.string().min(10, 'At least 10 characters'),
        password_confirmation: z.string().min(10, 'At least 10 characters'),
    })
    .refine((v) => v.password === v.password_confirmation, { message: 'Both passwords must match', path: ['password_confirmation'] });
type Form = z.infer<typeof schema>;

/** First-login screen: replace the admin-generated temporary password, then continue into the app. */
export default function SetPasswordPage() {
    const { user, loading } = useAuth();
    const qc = useQueryClient();
    const nav = useNavigate();
    const [params] = useSearchParams();
    const next = params.get('next') || '/';
    const form = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { password: '', password_confirmation: '' } });

    if (loading) return <div className="p-8 text-sm text-neutral-500">Loading…</div>;
    if (!user) {
        const back = params.toString() ? `/set-password?${params.toString()}` : '/set-password';
        return <Navigate to={`/login?next=${encodeURIComponent(back)}`} replace />;
    }
    if (!user.must_change_password) return <Navigate to={next.startsWith('/') ? next : '/'} replace />;

    const submit = form.handleSubmit(async (values) => {
        try {
            const res = await api<{ user: User }>('/auth/change-password', { method: 'POST', json: values });
            qc.setQueryData(['me'], res.user);
            nav(next.startsWith('/') ? next : '/', { replace: true });
        } catch (e) {
            const f = fieldErrors(e);
            if (f.password) form.setError('password', { message: f.password });
            else if (f.password_confirmation) form.setError('password_confirmation', { message: f.password_confirmation });
            else form.setError('root', { message: (e as Error).message });
        }
    });

    return (
        <div className="grid min-h-screen place-items-center bg-gradient-to-b from-white to-lia-50/40 px-4">
            <Card className="w-full max-w-sm p-6">
                <Brand />
                <h1 className="mt-6 flex items-center gap-2 text-lg font-semibold"><KeyRound className="h-4 w-4 text-lia-600" />Set your new password</h1>
                <p className="mt-1 text-sm text-neutral-500">
                    You signed in with the temporary password from your welcome email. Choose your own password (at least 10 characters) to unlock the archive.
                </p>
                <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                    <Field label="New password" error={form.formState.errors.password?.message}>
                        <Input type="password" autoComplete="new-password" autoFocus {...form.register('password')} />
                    </Field>
                    <Field label="Confirm new password" error={form.formState.errors.password_confirmation?.message}>
                        <Input type="password" autoComplete="new-password" {...form.register('password_confirmation')} />
                    </Field>
                    {form.formState.errors.root ? <p role="alert" className="rounded-lg bg-lia-50 p-2 text-sm text-lia-700">{form.formState.errors.root.message}</p> : null}
                    <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
                        {form.formState.isSubmitting ? 'Saving…' : 'Set password and continue'}
                    </Button>
                </form>
            </Card>
        </div>
    );
}
