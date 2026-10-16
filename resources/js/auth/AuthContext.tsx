import { useQuery, useQueryClient } from '@tanstack/react-query';
import { createContext, useContext, type ReactNode } from 'react';
import { api, ApiError } from '@/lib/api';
import type { User } from '@/lib/types';

interface AuthState {
    user: User | null;
    loading: boolean;
    can: (ability: string) => boolean;
    refresh: () => Promise<unknown>;
    logout: () => Promise<void>;
}

const Ctx = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const qc = useQueryClient();
    const q = useQuery({
        queryKey: ['me'],
        queryFn: async () => {
            try {
                return (await api<{ user: User }>('/auth/me')).user;
            } catch (e) {
                if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return null;
                throw e;
            }
        },
        staleTime: 60_000,
    });
    const user = q.data ?? null;
    const value: AuthState = {
        user,
        loading: q.isLoading,
        can: (a) => !!user && (user.role === 'admin' || user.abilities.includes(a)),
        refresh: () => q.refetch(),
        logout: async () => {
            await api('/auth/logout', { method: 'POST' }).catch(() => null);
            qc.clear();
            qc.setQueryData(['me'], null);
        },
    };
    return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth(): AuthState {
    const v = useContext(Ctx);
    if (!v) throw new Error('useAuth outside AuthProvider');
    return v;
}
