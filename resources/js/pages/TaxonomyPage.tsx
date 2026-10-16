import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Button } from '@/components/ui/button';
import { Card, PageHeader } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/input';
import { useLookups } from '@/hooks/useLookups';
import { api, fieldErrors } from '@/lib/api';
import { cn } from '@/lib/utils';

type Type = 'projects' | 'events' | 'cohorts' | 'categories';
const TABS: { type: Type; label: string; filter: string }[] = [
    { type: 'projects', label: 'Projects', filter: 'project_id' },
    { type: 'events', label: 'Events', filter: 'event_id' },
    { type: 'cohorts', label: 'Cohorts', filter: 'cohort_id' },
    { type: 'categories', label: 'Categories', filter: 'category_id' },
];

type Row = Record<string, string | number | null> & { id: number; assets_count: number };

export default function TaxonomyPage() {
    const [tab, setTab] = useState<Type>('projects');
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [error, setError] = useState('');
    const { can } = useAuth();
    const qc = useQueryClient();
    const lk = useLookups().data;
    const q = useQuery({ queryKey: ['taxonomy', tab], queryFn: () => api<{ data: Row[] }>(`/taxonomy/${tab}`) });
    const done = () => { setDraft({}); setError(''); qc.invalidateQueries({ queryKey: ['taxonomy', tab] }); qc.invalidateQueries({ queryKey: ['lookups'] }); };
    const create = useMutation({ mutationFn: () => api(`/taxonomy/${tab}`, { method: 'POST', json: Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === '' ? null : k.endsWith('_id') || k === 'year' ? Number(v) : v])) }), onSuccess: done, onError: (e) => setError(Object.values(fieldErrors(e))[0] || (e as Error).message) });
    const del = useMutation({ mutationFn: (id: number) => api(`/taxonomy/${tab}/${id}`, { method: 'DELETE' }), onSuccess: done, onError: (e) => setError(Object.values(fieldErrors(e))[0] || (e as Error).message) });
    const t = TABS.find((x) => x.type === tab)!;
    const inp = (k: string, label: string, type = 'text') => <Field label={label}><Input type={type} value={draft[k] ?? ''} onChange={(e) => setDraft({ ...draft, [k]: e.target.value })} /></Field>;
    return (
        <>
            <PageHeader title="Projects, events, cohorts & categories" description="How the archive is organised." />
            <div className="mb-4 flex gap-1 overflow-x-auto">
                {TABS.map((x) => <button key={x.type} onClick={() => { setTab(x.type); setDraft({}); setError(''); }} className={cn('rounded-full px-3 py-1.5 text-sm', tab === x.type ? 'bg-lia-600 text-white' : 'bg-white text-neutral-600 ring-1 ring-neutral-200')}>{x.label}</button>)}
            </div>
            <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                <Card className="divide-y divide-neutral-100">
                    {(q.data?.data ?? []).map((r) => (
                        <div key={r.id} className="flex items-center gap-3 p-3 text-sm">
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">{r.name ?? r.title}{r.code ? <span className="ml-2 text-xs text-neutral-500">{r.code}</span> : null}</div>
                                <div className="text-xs text-neutral-500">{[r.occurred_on && String(r.occurred_on).slice(0, 10), r.location, r.year, r.status].filter(Boolean).join(' · ')}</div>
                            </div>
                            <Link className="text-xs text-lia-700 hover:underline" to={`/library?${t.filter}=${r.id}`}>{r.assets_count} items</Link>
                            {can('taxonomy.manage') ? <Button size="sm" variant="ghost" onClick={() => del.mutate(r.id)}>Delete</Button> : null}
                        </div>
                    ))}
                    {q.data && !q.data.data.length ? <p className="p-4 text-sm text-neutral-500">None yet.</p> : null}
                </Card>
                {can('taxonomy.manage') ? (
                    <Card className="h-fit space-y-3 p-4">
                        <h2 className="text-sm font-semibold">Add {t.label.toLowerCase().replace(/s$/, '').replace(/ie$/, 'y')}</h2>
                        {tab === 'projects' ? <>{inp('name', 'Name')}{inp('starts_on', 'Starts', 'date')}{inp('ends_on', 'Ends', 'date')}</> : null}
                        {tab === 'events' ? <>{inp('title', 'Title')}{inp('occurred_on', 'Date', 'date')}{inp('location', 'Location')}
                            <Field label="Project"><Select value={draft.project_id ?? ''} onChange={(e) => setDraft({ ...draft, project_id: e.target.value })}><option value="">—</option>{lk?.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field></> : null}
                        {tab === 'cohorts' ? <>{inp('name', 'Name')}{inp('code', 'Code')}{inp('year', 'Year', 'number')}</> : null}
                        {tab === 'categories' ? <>{inp('name', 'Name')}</> : null}
                        {error ? <p role="alert" className="text-xs text-lia-700">{error}</p> : null}
                        <Button onClick={() => create.mutate()} disabled={create.isPending}>Add</Button>
                    </Card>
                ) : null}
            </div>
        </>
    );
}
