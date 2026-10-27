import { useQuery } from '@tanstack/react-query';
import { Link, Navigate } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import AssetTile from '@/components/media/AssetTile';
import { Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/components/ui/card';
import { api } from '@/lib/api';
import type { AssetCard } from '@/lib/types';
import { formatBytes } from '@/lib/utils';

type Dash = {
    review_counts: Record<string, number>; consent_issues: number; uploads_last_7_days: number; open_uploads: number; active_shares: number;
    storage: { total_bytes: number; asset_count: number; estimated_monthly_usd: number; monthly_budget_usd: number }; recent: AssetCard[];
};

function Stat({ label, value, to, warn }: { label: string; value: React.ReactNode; to?: string; warn?: boolean }) {
    const inner = <Card className={warn ? 'border-lia-200 bg-lia-50/50' : ''}><CardContent className="p-4"><div className="text-xs text-neutral-500">{label}</div><div className={`mt-1 text-2xl font-semibold ${warn ? 'text-lia-700' : ''}`}>{value}</div></CardContent></Card>;
    return to ? <Link to={to} className="block hover:opacity-90">{inner}</Link> : inner;
}

export default function DashboardPage() {
    const { user, can } = useAuth();
    const q = useQuery({ queryKey: ['dashboard'], queryFn: () => api<Dash>('/dashboard'), enabled: can('dashboard.view') });
    const d = q.data;
    if (user && !can('dashboard.view')) {
        const home = [
            ['material-requests.issue', '/storekeeper'],
            ['material-requests.view', '/material-requests'],
            ['equipment.manage', '/equipment-inventory'],
            ['library.view', '/library'],
            ['users.manage', '/admin/users'],
        ].find(([a]) => can(a));
        return <Navigate to={home ? home[1] : '/profile'} replace />;
    }
    return (
        <>
            <PageHeader title={`Welcome, ${user?.name}`} description="Love In Arts media archive" />
            {d ? (
                <div className="space-y-5">
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Awaiting review" value={d.review_counts.unreviewed} to={can('review.approve') ? '/review' : undefined} warn={d.review_counts.unreviewed > 0} />
                        <Stat label="Approved / published" value={`${d.review_counts.approved} / ${d.review_counts.published}`} />
                        <Stat label="Consent issues (shareable items)" value={d.consent_issues} warn={d.consent_issues > 0} />
                        <Stat label="Uploads, last 7 days" value={d.uploads_last_7_days} />
                        <Stat label="Items in archive" value={d.storage.asset_count} to="/library" />
                        <Stat label="Storage used" value={formatBytes(d.storage.total_bytes)} to="/storage" />
                        <Stat label="Est. storage cost / month" value={`$${d.storage.estimated_monthly_usd.toFixed(2)}`} warn={d.storage.estimated_monthly_usd > d.storage.monthly_budget_usd} to="/storage" />
                        <Stat label="Active share links" value={d.active_shares} to={can('shares.manage') ? '/shares' : undefined} />
                    </div>
                    <Card>
                        <CardHeader><CardTitle>Recently added</CardTitle></CardHeader>
                        <CardContent>{d.recent.length ? <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">{d.recent.map((a) => <AssetTile key={a.uuid} asset={a} />)}</div> : <p className="text-sm text-neutral-500">Nothing uploaded yet.</p>}</CardContent>
                    </Card>
                </div>
            ) : q.isLoading ? <p className="text-sm text-neutral-500">Loading…</p> : null}
        </>
    );
}
