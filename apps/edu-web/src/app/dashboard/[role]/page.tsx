import { notFound } from 'next/navigation';
import { roles, type Role } from '../../../lib/roles';
import ProtectedDashboard from '../../../lib/protected-dashboard';
export default async function Dashboard({ params }: { params: Promise<{ role: string }> }) {
  const { role } = await params;
  if (!roles.includes(role as Role)) notFound();
  return <ProtectedDashboard role={role} />;
}
