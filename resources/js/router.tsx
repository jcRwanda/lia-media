import { createBrowserRouter } from 'react-router';
import AppLayout, { RequireAbility } from '@/layouts/AppLayout';
import LoginPage from '@/pages/LoginPage';
import SetPasswordPage from '@/pages/SetPasswordPage';
import ProfilePage from '@/pages/ProfilePage';
import DashboardPage from '@/pages/DashboardPage';
import UsersPage from '@/pages/admin/UsersPage';
import RolesPage from '@/pages/admin/RolesPage';
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
import MaterialRequestsPage from '@/pages/material-requests/MaterialRequestsPage';
import MaterialRequestDetailPage from '@/pages/material-requests/MaterialRequestDetailPage';
import EquipmentInventoryPage from '@/pages/material-requests/EquipmentInventoryPage';
import EquipmentCalendarPage from '@/pages/material-requests/EquipmentCalendarPage';
import StorekeeperPortalPage from '@/pages/storekeeper/StorekeeperPortalPage';
import PublicQrPage from '@/pages/equipment/PublicQrPage';
import EquipmentScanPage from '@/pages/equipment/EquipmentScanPage';
import EquipmentLabelsPage from '@/pages/equipment/EquipmentLabelsPage';

const guard = (ability: string, el: React.ReactNode) => <RequireAbility ability={ability}>{el}</RequireAbility>;

export const routes = [
    { path: '/login', element: <LoginPage /> },
    { path: '/set-password', element: <SetPasswordPage /> },
    { path: '/s/:token', element: <VisitorGalleryPage /> },
    { path: '/q/:token', element: <PublicQrPage /> },
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
            { path: '/admin/roles', element: guard('roles.manage', <RolesPage />) },
            { path: '/admin/audit', element: guard('audit.view', <AuditPage />) },
            { path: '/storekeeper', element: guard('material-requests.issue', <StorekeeperPortalPage />) },
            { path: '/material-requests', element: guard('material-requests.view', <MaterialRequestsPage />) },
            { path: '/material-requests/:id', element: guard('material-requests.view', <MaterialRequestDetailPage />) },
            { path: '/storekeeper/scan', element: guard('material-requests.issue', <EquipmentScanPage />) },
            { path: '/equipment-labels', element: guard('equipment.manage', <EquipmentLabelsPage />) },
            { path: '/equipment-inventory', element: guard('equipment.manage', <EquipmentInventoryPage />) },
            { path: '/equipment-calendar', element: guard('material-requests.view', <EquipmentCalendarPage />) },
            { path: '/profile', element: <ProfilePage /> },
            { path: '*', element: <p className="text-sm text-neutral-500">Page not found.</p> },
        ],
    },
];

export const router = createBrowserRouter(routes);

// Material Requests (staff)
