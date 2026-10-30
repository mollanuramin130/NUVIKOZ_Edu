'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AuthError, browserRequest, logout, onSessionChange, type Identity } from './browser-auth';
export default function ProtectedDashboard({ role }: { role: string }) {
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [error, setError] = useState('');
  const router = useRouter();
  useEffect(() => {
    let active = true;
    let generation = 0;
    function verify() {
      const current = ++generation;
      setIdentity(null); setError('');
      browserRequest<Identity>('me').then(data => {
        if (!active || current !== generation) return;
        if (data.mfa_required) { router.replace('/profile/security'); return; }
        if (data.role !== role) { router.replace('/access-denied'); return; }
        setIdentity(data);
      }).catch(e => {
        if (!active || current !== generation) return;
        if (e instanceof AuthError && (e.status === 401 || e.status === 419)) router.replace('/session-expired');
        else if (e instanceof AuthError && e.status === 403) router.replace('/access-denied');
        else setError('The session could not be verified. Retry or sign in again.');
      });
    }
    const unsubscribe = onSessionChange(verify);
    const visible = () => { if (document.visibilityState === 'visible') verify(); };
    window.addEventListener('focus', verify);
    document.addEventListener('visibilitychange', visible);
    verify();
    return () => { active = false; unsubscribe(); window.removeEventListener('focus', verify); document.removeEventListener('visibilitychange', visible); };
  }, [role, router]);
  async function signOut() {
    try { await logout(); setIdentity(null); router.replace('/login'); }
    catch (e) {
      if (e instanceof AuthError && e.status === 419) { setError(e.message); return; }
      setError('Sign out failed. Please retry.');
    }
  }
  if (error) return <main><h1>Unable to verify access</h1><p role="alert">{error}</p><button onClick={signOut}>Retry sign out</button><a href="/login">Sign in</a></main>;
  if (!identity) return <main><p role="status">Checking your session…</p></main>;
  return <main><p>NUVIKOZ Edu</p><h1>{role.replaceAll('_', ' ')} dashboard</h1><p>School context: {identity.school_id}</p><p>{role === 'school_admin' ? 'Academic setup is available for your verified school context.' : 'Academic modules for this role are not yet connected.'}</p>{role === 'school_admin' && <><Link href="/academic">Academic setup</Link><a href="/assisted-recovery">Assisted password recovery</a><Link href="/security/permissions">School permissions</Link></>}<Link href="/profile/sessions">Sessions and devices</Link><button onClick={signOut}>Sign out</button></main>;
}
