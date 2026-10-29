'use client';
import { useState, type FormEvent } from 'react';
import { browserRequest } from '../../lib/browser-auth';
export default function ForgotPassword() {
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [complete, setComplete] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; setBusy(true); setError('');
    try { await browserRequest('recovery/request', Object.fromEntries(new FormData(form))); form.reset(); setComplete(true); }
    catch (e) { setError(e instanceof Error ? e.message : 'Request failed. Please retry.'); }
    finally { setBusy(false); }
  }
  if (complete) return <main className="auth-card"><h1>Recovery request received</h1><p>If recovery is available, follow the authorized instructions. Otherwise contact your School Admin for identity verification.</p><p>Automated contact delivery is not available yet. No email or message has been sent.</p><a href="/reset-password">Use a School Admin recovery proof</a><a href="/login">Return to sign in</a></main>;
  return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Forgot password</h1><p>Use your school code and assigned identifier. Do not enter a shared phone number as identity verification.</p><form onSubmit={submit} aria-busy={busy}>
    <label>School code<input name="school_code" required maxLength={32} autoComplete="organization" /></label>
    <label>Account identifier<input name="identifier" required maxLength={80} autoComplete="username" /></label>
    {error && <p role="alert">{error}</p>}<button disabled={busy}>{busy ? 'Requesting…' : 'Request recovery'}</button>
  </form><a href="/reset-password">I have a recovery proof</a><a href="/login">Return to sign in</a></main>;
}
