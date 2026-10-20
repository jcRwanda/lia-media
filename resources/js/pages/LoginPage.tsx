import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Navigate, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { Brand } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, ensureCsrf, fieldErrors } from '@/lib/api';
import type { User } from '@/lib/types';

const schema = z.object({ email: z.string().email('Enter a valid email'), password: z.string().min(1, 'Enter your password') });
type Form = z.infer<typeof schema>;

export default function LoginPage() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const nav = useNavigate();
    const [params] = useSearchParams();
    const [error, setError] = useState('');
    const form = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } });
    const next = params.get('next') || '/';
    if (user) return <Navigate to={next} replace />;

    const submit = form.handleSubmit(async (values) => {
        setError('');
        try {
            await ensureCsrf();
            const res = await api<{ user: User }>('/auth/login', { method: 'POST', json: values });
            qc.setQueryData(['me'], res.user);
            const target = next.startsWith('/') ? next : '/';
            // The welcome-email temp password has to be replaced before anything else loads.
            nav(res.user.must_change_password ? `/set-password?next=${encodeURIComponent(target)}` : target, { replace: true });
        } catch (e) {
            const f = fieldErrors(e);
            setError(f.email || (e as Error).message);
        }
    });

    return (
        <div className="grid min-h-screen place-items-center bg-gradient-to-b from-white to-lia-50/40 px-4">
            <Card className="w-full max-w-sm p-6">
                <Brand />
                <h1 className="mt-6 text-lg font-semibold">Sign in</h1>
                <p className="text-sm text-neutral-500">Staff access to the Love In Arts media archive.</p>
                <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                    <Field label="Email" error={form.formState.errors.email?.message}><Input type="email" autoComplete="username" {...form.register('email')} /></Field>
                    <Field label="Password" error={form.formState.errors.password?.message}><Input type="password" autoComplete="current-password" {...form.register('password')} /></Field>
                    {error ? <p role="alert" className="rounded-lg bg-lia-50 p-2 text-sm text-lia-700">{error}</p> : null}
                    <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>{form.formState.isSubmitting ? 'Signing in…' : 'Sign in'}</Button>
                </form>
            </Card>
        </div>
    );
}
