import Link from 'next/link';
import { roles } from '../lib/roles';
export default function Home() { return <main><h1>NUVIKOZ Edu</h1><p>Development scaffold. No protected school records are loaded.</p><ul>{roles.map(role => <li key={role}><Link href={'/dashboard/' + role}>{role.replaceAll('_', ' ')}</Link></li>)}</ul></main>; }
