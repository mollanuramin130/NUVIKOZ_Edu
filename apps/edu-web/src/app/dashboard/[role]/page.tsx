import { notFound } from 'next/navigation';
import { roles, type Role } from '../../../lib/roles';
export default async function Dashboard({ params }: { params: Promise<{ role: string }> }) { const { role } = await params; if (!roles.includes(role as Role)) notFound(); return <main><h1>{role.replaceAll('_', ' ')}</h1><p>Role layout placeholder; backend authorization is required before protected data is connected.</p></main>; }
