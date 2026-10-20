import { createBrowserRouter } from 'react-router';
import AppLayout, { RequireAbility } from '@/layouts/AppLayout';
import LoginPage from '@/pages/LoginPage';
import SetPasswordPage from '@/pages/SetPasswordPage';
import DashboardPage from '@/pages/DashboardPage';
import UsersPage from '@/pages/admin/UsersPage';
import AuditPage from '@/pages/admin/AuditPage';
import UploadPage from '@/pages/UploadPage';
import LibraryPage from '@/pages/LibraryPage';
import AssetPage from '@/pages/AssetPage';
import AlbumsPage from '@/pages/AlbumsPage';
import AlbumPage from '@/pages/AlbumPage';
import TaxonomyPage from '@/pages/TaxonomyPage';
import SharesPage from '@/pages/shares/SharesPage';
import ReviewQueuePage from '@/pages/review/ReviewQueuePage';
import StoragePage from '@/pages/StoragePage';
import SettingsPage from '@/pages/admin/SettingsPage';
import VisitorGalleryPage from '@/pages/shares/VisitorGalleryPage';

const guard = (ability: string, el: React.ReactNode) => <RequireAbility ability={ability}>{el}</RequireAbility>;

export const routes = [
    { path: '/login', element: <LoginPage /> },
    { path: '/set-password', element: <SetPasswordPage /> },
    { path: '/s/:token', element: <VisitorGalleryPage /> },
    {
        element: <AppLayout />,
        children: [
            { path: '/', element: <DashboardPage /> },
            { path: '/library', element: guard('library.view', <LibraryPage />) },
            { path: '/assets/:uuid', element: guard('library.view', <AssetPage />) },
            { path: '/albums', element: guard('library.view', <AlbumsPage />) },
            { path: '/albums/:uuid', element: guard('library.view', <AlbumPage />) },
            { path: '/taxonomy', element: guard('library.view', <TaxonomyPage />) },
            { path: '/shares', element: guard('shares.manage', <SharesPage />) },
            { path: '/review', element: guard('review.approve', <ReviewQueuePage />) },
            { path: '/storage', element: guard('dashboard.view', <StoragePage />) },
            { path: '/admin/settings', element: guard('settings.manage', <SettingsPage />) },
            { path: '/upload', element: guard('assets.upload', <UploadPage />) },
            { path: '/admin/users', element: guard('users.manage', <UsersPage />) },
            { path: '/admin/audit', element: guard('audit.view', <AuditPage />) },
            { path: '*', element: <p className="text-sm text-neutral-500">Page not found.</p> },
        ],
    },
];

export const router = createBrowserRouter(routes);
