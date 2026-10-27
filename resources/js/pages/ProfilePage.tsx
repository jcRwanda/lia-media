import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/button';
import { Card, PageHeader } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/input';
import { api, fieldErrors } from '@/lib/api';
import type { User } from '@/lib/types';

const profileSchema = z.object({
    name: z.string().min(2),
    phone: z.string().max(40).optional().or(z.literal('')),
});

const passwordSchema = z
    .object({
        current_password: z.string().min(1, 'Current password is required'),
        password: z.string().min(10, 'At least 10 characters'),
        password_confirmation: z.string().min(10, 'At least 10 characters'),
    })
    .refine((v) => v.password === v.password_confirmation, { message: 'Both passwords must match', path: ['password_confirmation'] });

export default function ProfilePage() {
    const { user, refresh } = useAuth();
    const qc = useQueryClient();
    const [profileMsg, setProfileMsg] = useState('');
    const [passwordMsg, setPasswordMsg] = useState('');
    const [photoMsg, setPhotoMsg] = useState('');

    const profileForm = useForm<z.infer<typeof profileSchema>>({
        resolver: zodResolver(profileSchema),
        defaultValues: { name: user?.name || '', phone: (user as any)?.phone || '' },
    });
    const passwordForm = useForm<z.infer<typeof passwordSchema>>({
        resolver: zodResolver(passwordSchema),
        defaultValues: { current_password: '', password: '', password_confirmation: '' },
    });

    const updateProfile = useMutation({
        mutationFn: (v: z.infer<typeof profileSchema>) => api<{ user: User }>('/auth/me', { method: 'PATCH', json: { name: v.name, phone: v.phone || null } }),
        onSuccess: (r) => {
            qc.setQueryData(['me'], r.user);
            setProfileMsg('Profile updated');
            setTimeout(() => setProfileMsg(''), 2500);
        },
        onError: (e) => {
            Object.entries(fieldErrors(e)).forEach(([k, m]) => profileForm.setError(k as never, { message: m }));
        },
    });

    const changePassword = useMutation({
        mutationFn: (v: z.infer<typeof passwordSchema>) => api('/auth/change-password-with-current', { method: 'POST', json: v }),
        onSuccess: () => {
            passwordForm.reset();
            setPasswordMsg('Password updated');
            setTimeout(() => setPasswordMsg(''), 2500);
        },
        onError: (e) => {
            const f = fieldErrors(e);
            if (f.current_password) passwordForm.setError('current_password', { message: f.current_password });
            else if (f.password) passwordForm.setError('password', { message: f.password });
            else if (f.password_confirmation) passwordForm.setError('password_confirmation', { message: f.password_confirmation });
            else passwordForm.setError('root', { message: (e as Error).message });
        },
    });

    const uploadPhoto = async (file: File) => {
        setPhotoMsg('');
        try {
            // Try to get presigned upload URL if endpoint exists; otherwise upload directly
            const res: any = await api('/auth/me/photo', {
                method: 'POST',
                json: { filename: file.name, content_type: file.type },
            }).catch(async () => {
                // Fallback: direct upload as form data
                const formData = new FormData();
                formData.append('photo', file);
                const r = await fetch('/api/auth/me/photo', { method: 'POST', body: formData, credentials: 'include' });
                if (!r.ok) throw new Error('Upload failed');
                return r.json();
            });
            qc.setQueryData(['me'], (cur: any) => cur ? { ...cur, photo_url: res.photo_url || res.user?.photo_url } : cur);
            refresh();
            setPhotoMsg('Photo updated');
            setTimeout(() => setPhotoMsg(''), 2500);
        } catch (e) {
            setPhotoMsg((e as Error).message);
        }
    };

    if (!user) return null;

    return (
        <>
            <PageHeader title="My Profile" description="Update your personal details and password." />
            <div className="grid gap-5 lg:grid-cols-2">
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">Personal details</h2>
                    <form className="mt-3 space-y-3" onSubmit={profileForm.handleSubmit((v) => updateProfile.mutate(v))} noValidate>
                        <Field label="Name" error={profileForm.formState.errors.name?.message}>
                            <Input {...profileForm.register('name')} />
                        </Field>
                        <Field label="Email">
                            <Input value={user.email} disabled />
                        </Field>
                        <Field label="Phone" error={(profileForm.formState.errors as any).phone?.message}>
                            <Input {...profileForm.register('phone')} />
                        </Field>
                        {profileMsg ? <p className="text-xs text-emerald-700">{profileMsg}</p> : null}
                        <Button type="submit" disabled={updateProfile.isPending}>Save profile</Button>
                    </form>
                </Card>
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">Profile photo</h2>
                    <p className="mt-1 text-xs text-neutral-500">Upload a photo (stored via object storage).</p>
                    <div className="mt-3 flex items-center gap-3">
                        {(user as any).photo_url ? (
                            <img src={(user as any).photo_url} alt="Profile" className="h-16 w-16 rounded-full object-cover" />
                        ) : (
                            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-neutral-100 text-lg font-semibold">{user.name.charAt(0)}</div>
                        )}
                        <label className="cursor-pointer">
                            <input type="file" accept="image/*" className="hidden" onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) uploadPhoto(f);
                            }} />
                            <span className="inline-flex h-9 items-center justify-center rounded-lg border border-neutral-200 bg-white px-3 text-sm font-medium hover:bg-neutral-50">Upload photo</span>
                        </label>
                    </div>
                    {photoMsg ? <p className="mt-2 text-xs text-neutral-600">{photoMsg}</p> : null}
                </Card>
                <Card className="p-4 lg:col-span-2">
                    <h2 className="text-sm font-semibold">Change password</h2>
                    <p className="mt-1 text-xs text-neutral-500">Enter your current password to set a new one.</p>
                    <form className="mt-3 grid gap-3 sm:grid-cols-3" onSubmit={passwordForm.handleSubmit((v) => changePassword.mutate(v))} noValidate>
                        <Field label="Current password" error={passwordForm.formState.errors.current_password?.message}>
                            <Input type="password" {...passwordForm.register('current_password')} />
                        </Field>
                        <Field label="New password" error={passwordForm.formState.errors.password?.message}>
                            <Input type="password" {...passwordForm.register('password')} />
                        </Field>
                        <Field label="Confirm new password" error={passwordForm.formState.errors.password_confirmation?.message}>
                            <Input type="password" {...passwordForm.register('password_confirmation')} />
                        </Field>
                        {passwordForm.formState.errors.root ? <p role="alert" className="text-sm text-lia-700 sm:col-span-3">{passwordForm.formState.errors.root.message}</p> : null}
                        {passwordMsg ? <p className="text-xs text-emerald-700 sm:col-span-3">{passwordMsg}</p> : null}
                        <div className="sm:col-span-3">
                            <Button type="submit" disabled={changePassword.isPending}>Update password</Button>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}
