'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AuthError, browserRequest, logout, type Identity } from './browser-auth';
export default function ProtectedDashboard({ role }: { role: string }) {
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [error, setError] = useState('');
  const router = useRouter();
  useEffect(() => {
    let active = true;
    browserRequest<Identity>('me').then(data => {
      if (!active) return;
      if (data.role !== role) { router.replace('/access-denied'); return; }
      setIdentity(data);
    }).catch(e => {
      if (!active) return;
      if (e instanceof AuthError && e.status === 401) router.replace('/session-expired');
      else if (e instanceof AuthError && e.status === 403) router.replace('/access-denied');
      else setError('The session could not be verified. Retry or sign in again.');
    });
    return () => { active = false; };
  }, [role, router]);
  async function signOut() {
    try { await logout(); setIdentity(null); router.replace('/login'); }
    catch { setError('Sign out failed. Please retry.'); }
  }
  if (error) return <main><h1>Unable to verify access</h1><p role="alert">{error}</p><a href="/login">Sign in</a></main>;
  if (!identity) return <main><p role="status">Checking your session…</p></main>;
  return <main><p>NUVIKOZ Edu</p><h1>{role.replaceAll('_', ' ')} dashboard</h1><p>School context: {identity.school_id}</p><p>Academic modules are not yet connected.</p><button onClick={signOut}>Sign out</button></main>;
}
