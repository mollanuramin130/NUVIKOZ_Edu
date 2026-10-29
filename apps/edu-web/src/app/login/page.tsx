'use client';
import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { browserRequest, type Identity } from '../../lib/browser-auth';

export default function Login() {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const router = useRouter();
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError('');
    const form = new FormData(event.currentTarget);
    try {
      const identity = await browserRequest<Identity>('login', { school_code: form.get('school_code'), identifier: form.get('identifier'), password: form.get('password') });
      router.replace(`/dashboard/${encodeURIComponent(identity.role)}`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Sign in failed.'); }
    finally { setBusy(false); }
  }
  return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Sign in to your school</h1><p>Use the school code and account identifier provided by your School Admin.</p>
    <form onSubmit={submit} aria-busy={busy}>
      <label>School code<input name="school_code" required maxLength={32} autoComplete="organization" /></label>
      <label>Account identifier<input name="identifier" required maxLength={80} autoComplete="username" /></label>
      <label>Password<input name="password" type="password" required maxLength={72} autoComplete="current-password" /></label>
      {error && <p role="alert">{error}</p>}<button disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
    </form><p>Have a School Admin activation proof? <a href="/activate">Activate your account</a>. <a href="/forgot-password">Forgot password?</a></p></main>;
}
