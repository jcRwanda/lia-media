import { createBrowserRouter } from 'react-router';
import AppLayout, { RequireAbility } from '@/layouts/AppLayout';
import LoginPage from '@/pages/LoginPage';
import DashboardPage from '@/pages/DashboardPage';
import UsersPage from '@/pages/admin/UsersPage';
import AuditPage from '@/pages/admin/AuditPage';
import UploadPage from '@/pages/UploadPage';
import LibraryPage from '@/pages/LibraryPage';
import AssetPage from '@/pages/AssetPage';
import AlbumsPage from '@/pages/AlbumsPage';
import AlbumPage from '@/pages/AlbumPage';
import TaxonomyPage from '@/pages/TaxonomyPage';

const guard = (ability: string, el: React.ReactNode) => <RequireAbility ability={ability}>{el}</RequireAbility>;

export const routes = [
    { path: '/login', element: <LoginPage /> },
    {
        element: <AppLayout />,
        children: [
            { path: '/', element: <DashboardPage /> },
            { path: '/library', element: guard('library.view', <LibraryPage />) },
            { path: '/assets/:uuid', element: guard('library.view', <AssetPage />) },
            { path: '/albums', element: guard('library.view', <AlbumsPage />) },
            { path: '/albums/:uuid', element: guard('library.view', <AlbumPage />) },
            { path: '/taxonomy', element: guard('library.view', <TaxonomyPage />) },
            { path: '/upload', element: guard('assets.upload', <UploadPage />) },
            { path: '/admin/users', element: guard('users.manage', <UsersPage />) },
            { path: '/admin/audit', element: guard('audit.view', <AuditPage />) },
            { path: '*', element: <p className="text-sm text-neutral-500">Page not found.</p> },
        ],
    },
];

export const router = createBrowserRouter(routes);
