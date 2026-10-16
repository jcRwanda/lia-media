import { createBrowserRouter } from 'react-router';
import AppLayout, { RequireAbility } from '@/layouts/AppLayout';
import LoginPage from '@/pages/LoginPage';
import DashboardPage from '@/pages/DashboardPage';
import UsersPage from '@/pages/admin/UsersPage';
import AuditPage from '@/pages/admin/AuditPage';

const guard = (ability: string, el: React.ReactNode) => <RequireAbility ability={ability}>{el}</RequireAbility>;

export const routes = [
    { path: '/login', element: <LoginPage /> },
    {
        element: <AppLayout />,
        children: [
            { path: '/', element: <DashboardPage /> },
            { path: '/admin/users', element: guard('users.manage', <UsersPage />) },
            { path: '/admin/audit', element: guard('audit.view', <AuditPage />) },
            { path: '*', element: <p className="text-sm text-neutral-500">Page not found.</p> },
        ],
    },
];

export const router = createBrowserRouter(routes);
