import { PageHeader } from '@/components/ui/card';
import { useAuth } from '@/auth/AuthContext';

export default function DashboardPage() {
    const { user } = useAuth();
    return <PageHeader title={`Welcome, ${user?.name}`} description="Love In Arts media archive" />;
}
