 'use client';
import { useState, type FormEvent } from 'react';
import { AuthError, browserRequest } from '../../lib/browser-auth';
export default function Activate() {
 const [busy,setBusy]=useState(false);const [error,setError]=useState('');const [complete,setComplete]=useState(false);
 async function submit(event: FormEvent<HTMLFormElement>) {
  event.preventDefault();const form=event.currentTarget;const data=new FormData(form);setBusy(true);setError('');
  if(data.get('password')!==data.get('password_confirmation')){setError('Passwords must match.');setBusy(false);return;}
  try {await browserRequest('activation/consume',Object.fromEntries(data));form.reset();setComplete(true);}
  catch(e){setError(e instanceof AuthError && e.status===422 ? 'The activation proof is invalid, expired, already used, or no longer authorized. Ask your School Admin for a new proof.' : e instanceof Error ? e.message : 'Activation failed. Please retry.');}
  finally {setBusy(false);}
 }
 if(complete)return <main className="auth-card"><h1>Account activated</h1><p>Your password is set. This activation proof cannot be used again.</p><a href="/login">Continue to sign in</a></main>;
 return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Activate your account</h1><p>Use the one-time proof handed over by your School Admin within 30 minutes. Sign out of any other account first.</p>
 <form onSubmit={submit} aria-busy={busy}>
 <label>School code<input name="school_code" required maxLength={32} autoComplete="organization" /></label>
 <label>Activation ID<input name="proof_id" required minLength={26} maxLength={26} autoComplete="off" spellCheck={false} /></label>
 <label>Activation secret<input name="secret" type="password" required minLength={64} maxLength={64} autoComplete="off" /></label>
 <label>New password<input name="password" type="password" required minLength={12} maxLength={72} autoComplete="new-password" /></label>
 <label>Confirm password<input name="password_confirmation" type="password" required minLength={12} maxLength={72} autoComplete="new-password" /></label>
 {error&&<p role="alert">{error}</p>}<button disabled={busy}>{busy?'Activating…':'Activate account'}</button>
 </form><p>Never share your password. Activation proofs are not accepted through URL parameters.</p><a href="/login">Return to sign in</a></main>;
}
