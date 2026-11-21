'use client';
import Link from 'next/link';
import {usePathname, useRouter} from 'next/navigation';
import {createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode} from 'react';
import {AuthError, browserRequest, logout, onSessionChange, type Identity} from '../../lib/browser-auth';
import type {AcademicSession} from '../../lib/academic-api';

export const pages = [{href: '/academic', name: 'Overview', glyph: '◈'}, {href: '/academic/sessions', name: 'Sessions', glyph: '▦'}, {href: '/academic/classes', name: 'Classes & offerings', glyph: '▤'}, {href: '/academic/sections', name: 'Sections', glyph: '▥'}, {href: '/academic/subjects', name: 'Subjects', glyph: '◇'}];
type Access = {identity: Identity; session: AcademicSession | null; selectSession: (value: AcademicSession | null) => void; failure: (error: unknown) => void};
const AcademicContext = createContext<Access | null>(null);
export function useAcademicAccess() { const value = useContext(AcademicContext); if (!value) throw new Error('Academic workspace unavailable.'); return value; }

function PrivateWorkspace({identity, children, failure}: {identity: Identity; children: ReactNode; failure: (error: unknown) => void}) {
  const [session, selectSession] = useState<AcademicSession | null>(null);
  const [open, setOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [error, setError] = useState('');
  const menu = useRef<HTMLButtonElement>(null); const sidebar = useRef<HTMLElement>(null);
  function closeNavigation() {setOpen(false); if (open) requestAnimationFrame(() => menu.current?.focus());}
  useEffect(() => {
    const escape = (e: KeyboardEvent) => {if (open && e.key === 'Escape') {setOpen(false); menu.current?.focus();}};
    window.addEventListener('keydown', escape); return () => window.removeEventListener('keydown', escape);
  }, [open]);
  const router = useRouter(); const path = usePathname();
  async function leave() {
    setSigningOut(true); setError('');
    try { await logout(); router.replace('/login'); }
    catch (e) { failure(e); setError(e instanceof Error ? e.message : 'Sign out failed.'); setSigningOut(false); }
  }
  return <AcademicContext.Provider value={{identity, session, selectSession, failure}}>
    <div className="academic-workspace">
      <a className="ac-skip" href="#academic-content">Skip to academic content</a>
      <aside ref={sidebar} className={`ac-sidebar ${open ? 'is-open' : ''}`} id="academic-navigation" aria-label="Workspace sidebar">
        <Link href="/academic" className="ac-brand" onClick={closeNavigation}><span className="ac-monogram" aria-hidden="true">N</span><span>NUVIKOZ <b>EDU</b><small>School administration</small></span></Link>
        <p className="ac-eyebrow">ACADEMIC WORKSPACE</p>
        <nav aria-label="Academic navigation">{pages.map(p => <Link key={p.href} href={p.href} aria-current={path === p.href ? 'page' : undefined} onClick={closeNavigation}><span aria-hidden="true">{p.glyph}</span>{p.name}</Link>)}</nav>
        <div className="ac-sidebar-foot"><span className="ac-live-dot"/> School Admin workspace<p>Verified session · school-scoped access</p><Link href="/profile/security">Account security ↗</Link></div>
      </aside>
      <div className="ac-body">
        <header className="ac-topbar"><button ref={menu} className="ac-menu ac-secondary" aria-label="Toggle academic navigation" aria-controls="academic-navigation" aria-expanded={open} onClick={() => {setOpen(!open); if (!open) requestAnimationFrame(() => sidebar.current?.querySelector<HTMLAnchorElement>('nav a')?.focus());}}>☰</button><div><span className="ac-eyebrow">NUVIKOZ EDU / ACADEMIC SETUP</span><p>School reference · {identity.school_id.slice(-8)}</p></div><div className="ac-top-actions"><Link href="/dashboard/school_admin">Dashboard</Link><button className="ac-secondary" onClick={leave} disabled={signingOut}>{signingOut ? 'Signing out…' : 'Sign out'}</button></div></header>
        {error && <p role="alert" className="ac-banner">{error}</p>}
        <main id="academic-content" className="ac-main" tabIndex={-1}>{children}</main>
        <footer className="ac-footer">NUVIKOZ Edu <span>Academic setup · Authorized school context</span></footer>
      </div>
    </div>
  </AcademicContext.Provider>;
}

export default function AcademicWorkspace({children}: {children: ReactNode}) {
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [epoch, setEpoch] = useState(0); const [message, setMessage] = useState(''); const [error, setError] = useState('');
  const generation = useRef(0); const router = useRouter();
  const verify = useCallback(async () => {
    const current = ++generation.current;
    setIdentity(null); setError(''); // Unmount all private lists, forms, selectors and pending intents immediately.
    try {
      const who = await browserRequest<Identity>('me');
      if (current !== generation.current) return;
      if (who.role !== 'school_admin') { router.replace('/access-denied'); return; }
      if (who.mfa_required) { router.replace('/profile/security'); return; }
      setEpoch(x => x + 1); setIdentity(who);
    } catch (e) {
      if (current !== generation.current) return;
      if (e instanceof AuthError && [401, 419].includes(e.status)) router.replace('/session-expired');
      else if (e instanceof AuthError && e.code === 'MFA_REQUIRED') router.replace('/profile/security');
      else if (e instanceof AuthError && e.status === 403) router.replace('/access-denied');
      else setError('Your school session could not be verified. No academic data is displayed.');
    }
  }, [router]);
  const invalidate = useCallback(() => {generation.current++;}, []);
  useEffect(() => {
    let mounted = true;
    void Promise.resolve().then(() => {if (mounted) void verify();});
    const unsubscribe = onSessionChange(() => {setMessage('Your session changed. Review the refreshed context before submitting again.'); void verify();});
    const focus = () => {if (document.visibilityState === 'visible') void verify();};
    window.addEventListener('focus', focus); document.addEventListener('visibilitychange', focus);
    return () => {mounted = false; invalidate(); unsubscribe(); window.removeEventListener('focus', focus); document.removeEventListener('visibilitychange', focus);};
  }, [verify, invalidate]);
  const failure = useCallback((e: unknown) => {
    if (!(e instanceof AuthError)) return;
    if (e.status === 401) {generation.current++; setIdentity(null); router.replace('/session-expired');}
    else if (e.code === 'MFA_REQUIRED') {generation.current++; setIdentity(null); router.replace('/profile/security');}
    else if ([403, 419].includes(e.status)) {
      setMessage(e.status === 419 ? 'Your CSRF or session changed. No command was replayed. Review and submit again.' : 'That operation is unavailable under current school eligibility or configuration. Authorized reading may remain available.');
      void verify();
    }
  }, [router, verify]);
  if (!identity) return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Academic setup</h1>{error ? <><p role="alert">{error}</p><button onClick={() => void verify()}>Retry session check</button></> : <p role="status">Verifying your school and MFA…</p>}</main>;
  return <><div className="ac-session-message" role="status">{message}</div><PrivateWorkspace key={`${identity.user_id}:${identity.school_id}:${epoch}`} identity={identity} failure={failure}>{children}</PrivateWorkspace></>;
}
