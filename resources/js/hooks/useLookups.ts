import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Lookups } from '@/lib/types';

export function useLookups() {
    return useQuery({ queryKey: ['lookups'], queryFn: () => api<Lookups>('/lookups'), staleTime: 60_000 });
}
