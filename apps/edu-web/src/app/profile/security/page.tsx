'use client';
import { useEffect, useState, type FormEvent } from 'react';
import { browserRequest, logout, type Identity } from '../../../lib/browser-auth';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {MfaReplacement} from '../../../components/mfa-replacement';
type Status = {enrolled: boolean; verified: boolean};
export default function Security() {
  const [replacementDone,setReplacementDone]=useState(false);
  const [status,setStatus]=useState<Status|null>(null);const [seed,setSeed]=useState('');const [codes,setCodes]=useState<string[]>([]);
  const [error,setError]=useState('');const [busy,setBusy]=useState(false);const router=useRouter();
  useEffect(()=>{let active=true;browserRequest<Identity>('me').then(identity=>{
    if(identity.role!=='school_admin'){router.replace('/access-denied');return;}
    return browserRequest<Status>('security/mfa/status');
  }).then(result=>{if(active&&result)setStatus(result);}).catch(()=>{if(active)router.replace('/session-expired');});return()=>{active=false;};},[router]);
  async function submit(event:FormEvent<HTMLFormElement>){
    event.preventDefault();const form=event.currentTarget;const data=new FormData(form);setBusy(true);setError('');
    const action=String(data.get('action'));
    try{
      const result=await browserRequest<{secret?:string;recovery_codes?:string[];verified?:boolean}>(`security/mfa/${action}`,{password:data.get('password'),...(data.has('code')?{code:data.get('code')}: {})});
      form.reset();if(result.secret)setSeed(result.secret);if(result.recovery_codes)setCodes(result.recovery_codes);
      if(result.verified){setSeed('');setStatus({enrolled:true,verified:true});}
    }catch(e){setError(e instanceof Error?e.message:'MFA verification failed.');}finally{setBusy(false);}
  }
  async function signOut(){try{await logout();setSeed('');setCodes([]);router.replace('/login');}catch(e){setError(e instanceof Error?e.message:'Sign out failed.');}}
  if(replacementDone)return <main className="auth-card"><h1>Authenticator replaced</h1><p>Save these new recovery codes offline. Old sessions and device tokens were revoked. Fresh sign-in with the new authenticator is required.</p><ul>{codes.map(code=><li key={code}>{code}</li>)}</ul><Link href="/login">Return to school sign-in</Link></main>;
  if(!status)return <main><p role="status">Checking security status…</p></main>;
  const mode=seed?'enrollment/confirm':status.enrolled?'challenge':'enrollment';
  return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Account security</h1><p>{status.verified?'MFA verified for this school session for up to 15 minutes.':'Complete MFA before School Admin commands. Challenges expire five minutes after sign-in; sign in again if expired.'}</p>
    {seed&&<section aria-label="Authenticator setup"><h2>Set up your authenticator</h2><p>NUVIKOZ Edu uses SHA-1, six digits and 30-second periods. Enter this secret manually in your authenticator. It expires in ten minutes until confirmed. Never share it.</p><p style={{overflowWrap:'anywhere'}} data-testid="mfa-seed">{seed}</p></section>}
    {!status.verified&&<form onSubmit={submit} aria-busy={busy}><input type="hidden" name="action" value={mode}/><label>Current password<input type="password" name="password" required maxLength={72} autoComplete="current-password"/></label>
    {(seed||status.enrolled)&&<label>Authenticator code<input name="code" required pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" inputMode="numeric"/></label>}
    <button disabled={busy}>{seed?'Confirm enrollment':status.enrolled?'Verify MFA':'Begin enrollment'}</button></form>}
    {status.enrolled&&!status.verified&&<form onSubmit={submit} aria-busy={busy}><h2>Use a recovery code</h2><input type="hidden" name="action" value="recovery-code"/><label>Current password<input name="password" type="password" required maxLength={72} autoComplete="current-password"/></label><label>Recovery code<input name="code" type="password" required minLength={32} maxLength={32} autoComplete="off"/></label><button disabled={busy}>Verify recovery code</button></form>}
    {status.verified&&<><form onSubmit={submit} aria-busy={busy}><input type="hidden" name="action" value="recovery-codes/regenerate"/><label>Current password<input name="password" type="password" required maxLength={72} autoComplete="current-password"/></label><p>Regenerating invalidates all older recovery codes.</p><button disabled={busy}>Regenerate recovery codes</button></form><Link href="/dashboard/school_admin">Continue to dashboard</Link></>}
    {status.enrolled&&<MfaReplacement context="school" onComplete={newCodes=>{setSeed('');setCodes(newCodes);setReplacementDone(true);}}/>}
    {codes.length>0&&<section aria-label="New recovery codes"><h2>Save these once-only recovery codes</h2><p>Keep them securely offline. They cannot be retrieved later.</p><ul>{codes.map(code=><li key={code}>{code}</li>)}</ul><button onClick={()=>setCodes([])}>Dismiss codes</button></section>}
    {error&&<p role="alert">{error} Invalid or expired challenge? Sign out and sign in again.</p>}<button disabled={busy} onClick={signOut}>Sign out</button>
  </main>;
}
