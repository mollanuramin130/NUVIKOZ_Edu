 'use client';
import { useState, type FormEvent } from 'react';
import { AuthError, browserRequest } from '../../lib/browser-auth';
export default function ResetPassword() {
 const [busy,setBusy]=useState(false);const [error,setError]=useState('');const [complete,setComplete]=useState(false);
 async function submit(event: FormEvent<HTMLFormElement>) {
  event.preventDefault();const form=event.currentTarget;const data=new FormData(form);setBusy(true);setError('');
  if(data.get('password')!==data.get('password_confirmation')){setError('Passwords must match.');setBusy(false);return;}
  try {await browserRequest('recovery/consume',Object.fromEntries(data));form.reset();setComplete(true);}
  catch(e){setError(e instanceof AuthError && e.status===422 ? 'The recovery proof is invalid, expired, already used, or no longer authorized. Ask your School Admin for a new proof.' : e instanceof Error ? e.message : 'Recovery failed. Please retry.');}
  finally {setBusy(false);}
 }
 if(complete)return <main className="auth-card"><h1>Password reset</h1><p>Your password has changed. Previous sessions and device tokens are revoked. Sign in again. This recovery proof cannot be used again.</p><a href="/login">Continue to sign in</a></main>;
 return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Reset your password</h1><p>Use the one-time proof handed over by your School Admin within 15 minutes. Sign out of any other account first.</p>
 <form onSubmit={submit} aria-busy={busy}>
 <label>School code<input name="school_code" required maxLength={32} autoComplete="organization" /></label>
 <label>Recovery ID<input name="proof_id" required minLength={26} maxLength={26} autoComplete="off" spellCheck={false} /></label>
 <label>Recovery secret<input name="secret" type="password" required minLength={64} maxLength={64} autoComplete="off" /></label>
 <label>New password<input name="password" type="password" required minLength={12} maxLength={72} autoComplete="new-password" /></label>
 <label>Confirm password<input name="password_confirmation" type="password" required minLength={12} maxLength={72} autoComplete="new-password" /></label>
 {error&&<p role="alert">{error}</p>}<button disabled={busy}>{busy?'Resetting…':'Reset password'}</button>
 </form><p>Never share your password. Recovery proofs are not accepted through URL parameters.</p><a href="/login">Return to sign in</a></main>;
}
