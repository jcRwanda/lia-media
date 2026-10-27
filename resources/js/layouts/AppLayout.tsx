import { Archive, BarChart3, CalendarDays, ClipboardCheck, ClipboardList, FolderOpen, FolderTree, Images, LayoutDashboard, LogOut, Menu, ScrollText, Settings, Share2, Shield, ShieldCheck, Upload, User, Users, X } from 'lucide-react';
import { useState } from 'react';
import { NavLink, Navigate, Outlet, useLocation } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { cn } from '@/lib/utils';

interface NavItem {
    to: string;
    label: string;
    icon: typeof LayoutDashboard;
    ability: string;
    section?: string;
    end?: boolean;
}

const nav: NavItem[] = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, ability: 'dashboard.view', end: true },
    { to: '/library', label: 'Library', icon: Images, ability: 'library.view' },
    { to: '/upload', label: 'Upload', icon: Upload, ability: 'assets.upload' },
    { to: '/albums', label: 'Albums', icon: Archive, ability: 'library.view' },
    { to: '/review', label: 'Review', icon: ShieldCheck, ability: 'review.approve' },
    { to: '/shares', label: 'Share links', icon: Share2, ability: 'shares.manage' },
    { to: '/', section: 'Storekeeper', label: 'Dashboard', icon: LayoutDashboard, ability: 'material-requests.issue', end: true },
    { to: '/storekeeper', section: 'Storekeeper', label: 'Portal', icon: ClipboardCheck, ability: 'material-requests.issue' },
    { to: '/material-requests', section: 'Storekeeper', label: 'Material Requests', icon: ClipboardList, ability: 'material-requests.view' },
    { to: '/equipment-inventory', section: 'Storekeeper', label: 'Inventory', icon: FolderOpen, ability: 'equipment.manage' },
    { to: '/equipment-calendar', section: 'Storekeeper', label: 'Equipment calendar', icon: CalendarDays, ability: 'material-requests.view' },
    { to: '/taxonomy', label: 'Projects & events', icon: FolderTree, ability: 'library.view' },
    { to: '/storage', label: 'Storage', icon: BarChart3, ability: 'dashboard.view' },
    { to: '/admin/users', label: 'Users', icon: Users, ability: 'users.manage' },
    { to: '/admin/roles', label: 'Roles', icon: Shield, ability: 'roles.manage' },
    { to: '/admin/audit', label: 'Audit log', icon: ScrollText, ability: 'audit.view' },
    { to: '/admin/settings', label: 'Settings', icon: Settings, ability: 'settings.manage' },
];

export default function AppLayout() {
    const { user, loading, can, logout } = useAuth();
    const [open, setOpen] = useState(false);
    const loc = useLocation();
    if (loading) return <div className="p-8 text-sm text-neutral-500">Loading…</div>;
    if (!user) return <Navigate to={`/login?next=${encodeURIComponent(loc.pathname + loc.search)}`} replace />;
    // A temporary password locks every route: park the user on the set-password screen first.
    if (user.must_change_password) return <Navigate to={`/set-password?next=${encodeURIComponent(loc.pathname + loc.search)}`} replace />;
    const items = nav.filter((n) => can(n.ability));
    const groups = items.reduce<Record<string, typeof items>>((acc, item) => {
        const key = item.section ?? 'Main';
        acc[key] = [...(acc[key] ?? []), item];
        return acc;
    }, {});
    const links = (
        <nav className="space-y-4">
            {Object.entries(groups).map(([section, sectionItems]) => (
                <div key={section} className="space-y-0.5">
                    {section !== 'Main' ? (
                        <div className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-neutral-400">{section}</div>
                    ) : null}
                    {sectionItems.map((n) => (
                        <NavLink key={n.to} to={n.to} end={n.end} onClick={() => setOpen(false)}
                            className={({ isActive }) => cn('flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm', isActive ? 'bg-lia-50 font-medium text-lia-700' : 'text-neutral-600 hover:bg-neutral-100')}>
                            <n.icon className="h-4 w-4" />
                            {n.label}
                        </NavLink>
                    ))}
                </div>
            ))}
        </nav>
    );
    return (
        <div className="min-h-screen lg:flex">
            <aside className="hidden w-60 shrink-0 border-r border-neutral-200 bg-white p-4 lg:block">
                <Brand />
                <div className="mt-6">{links}</div>
            </aside>
            <div className="flex-1">
                <header className="sticky top-0 z-20 flex items-center justify-between border-b border-neutral-200 bg-white/90 px-4 py-3 backdrop-blur">
                    <button className="lg:hidden" aria-label="Open menu" onClick={() => setOpen(true)}><Menu className="h-5 w-5" /></button>
                    <div className="lg:hidden"><Brand /></div>
                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <NavLink to="/profile" className="hidden items-center gap-1 text-neutral-600 hover:text-lia-700 sm:flex">
                            <User className="h-4 w-4" />
                            <span>{user.name}</span>
                        </NavLink>
                        <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] capitalize text-neutral-600">{user.role}</span>
                        <button onClick={() => { logout(); }} className="text-neutral-500 hover:text-lia-700" aria-label="Sign out"><LogOut className="h-4 w-4" /></button>
                    </div>
                </header>
                {open ? (
                    <div className="fixed inset-0 z-30 bg-black/30 lg:hidden" onClick={() => setOpen(false)}>
                        <div className="h-full w-72 bg-white p-4" onClick={(e) => e.stopPropagation()}>
                            <div className="flex items-center justify-between"><Brand /><button aria-label="Close menu" onClick={() => setOpen(false)}><X className="h-5 w-5" /></button></div>
                            <div className="mt-6">{links}</div>
                        </div>
                    </div>
                ) : null}
                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6"><Outlet /></main>
            </div>
        </div>
    );
}

export function Brand() {
    return (
        <div className="flex items-center gap-2">
            <img src="/images/lia-logo.png" alt="Love In Arts" className="h-10 w-auto" />
            <div className="leading-tight">
                <div className="text-sm font-semibold">Media Archive</div>
            </div>
        </div>
    );
}

export function RequireAbility({ ability, children }: { ability: string; children: React.ReactNode }) {
    const { can } = useAuth();
    if (!can(ability)) return <div className="rounded-xl border border-lia-100 bg-lia-50 p-4 text-sm text-lia-700">You don’t have access to this page.</div>;
    return <>{children}</>;
}
