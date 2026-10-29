'use client';
import {useEffect, useState, type FormEvent} from 'react';
import {AuthError, browserRequest, onSessionChange} from '../../lib/browser-auth';
type Status={user_id:string;enrolled:boolean;verified:boolean};
type Identity={user_id:string;context:string;capabilities:string[]};
export default function Platform(){
  const [status,setStatus]=useState<Status|null>(null),[identity,setIdentity]=useState<Identity|null>(null);
  const [seed,setSeed]=useState(''),[codes,setCodes]=useState<string[]>([]),[error,setError]=useState('');
  const [busy,setBusy]=useState(true),[unavailable,setUnavailable]=useState(false),[expired,setExpired]=useState(false);
  async function refresh(){
    const next=await browserRequest<Status>('platform/status');setStatus(next);
    if(next.verified)setIdentity(await browserRequest<Identity>('platform/identity'));else setIdentity(null);
  }
  function failure(e:unknown){
    setError(e instanceof Error?e.message:'Authentication failed.');
    if(e instanceof AuthError&&(e.status===401||e.status===419)){setStatus(null);setIdentity(null);setSeed('');setCodes([]);setExpired(true);}
    if(e instanceof AuthError&&e.status===403){setIdentity(null);setUnavailable(true);}
  }
  useEffect(()=>{
    let active=true;
    browserRequest<Status>('platform/status').then(async next=>{if(!active)return;setStatus(next);if(next.verified){const current=await browserRequest<Identity>('platform/identity');if(active)setIdentity(current);}}).catch(e=>{if(!active||e instanceof AuthError&&e.status===401)return;failure(e);}).finally(()=>{if(active)setBusy(false);});
    const close=onSessionChange(()=>{setSeed('');setCodes([]);refresh().catch(failure);});
    return ()=>{active=false;close();};
  },[]);
  async function submit(event:FormEvent<HTMLFormElement>){
    event.preventDefault();const form=event.currentTarget,data=new FormData(form);setBusy(true);setError('');setExpired(false);
    const action=String(data.get('action'));
    const input=action==='login'?{identifier:data.get('identifier'),password:data.get('password')}:{password:data.get('password'),...(data.has('code')?{code:data.get('code')}: {})};
    try{
      const result=await browserRequest<{secret?:string;recovery_codes?:string[];verified?:boolean}>(`platform/${action}`,input);
      form.reset();if(result.secret)setSeed(result.secret);if(result.recovery_codes)setCodes(result.recovery_codes);
      if(result.verified)setSeed('');await refresh();
    }catch(e){failure(e);}finally{setBusy(false);}
  }
  async function signOut(){setBusy(true);try{await browserRequest('platform/logout',{});setStatus(null);setIdentity(null);setSeed('');setCodes([]);setError('');setExpired(false);}catch(e){failure(e);}finally{setBusy(false);}}
  const password=<label>Current password<input name="password" type="password" required maxLength={72} autoComplete="current-password"/></label>;
  return <main className="auth-card"><p>NUVIKOZ Edu</p><h1>Platform security</h1><p>Separate platform authentication. Production access remains unavailable pending approved operator provisioning.</p>
    {unavailable?<section><h2>Platform access unavailable</h2><p>This environment or identity cannot use platform authentication. No school privileges are granted here.</p></section>:<>
    {expired&&<h2>Platform session expired</h2>}
    {!status&&!busy&&<form onSubmit={submit} aria-busy={busy}><h2>Platform sign-in</h2><input name="action" type="hidden" value="login"/><label>Platform identifier<input name="identifier" required minLength={26} maxLength={26} autoComplete="username"/></label>{password}<button disabled={busy}>Sign in to platform</button></form>}
    {status&&!identity&&<section><h2>Pending MFA authentication</h2><p>Password authentication alone grants no platform authority. Complete MFA within five minutes of sign-in.</p>
      {seed&&<section aria-label="Platform authenticator setup"><h3>New authenticator</h3><p>Use SHA-1, six digits, 30-second periods. Keep this temporary setup secret private.</p><p data-testid="platform-seed" style={{overflowWrap:'anywhere'}}>{seed}</p></section>}
      <form onSubmit={submit} aria-busy={busy}><input name="action" type="hidden" value={seed?'mfa/confirm':status.enrolled?'mfa/challenge':'mfa/enrollment'}/>{password}
      {(seed||status.enrolled)&&<label>Authenticator code<input name="code" required pattern="[0-9]{6}" maxLength={6} inputMode="numeric" autoComplete="one-time-code"/></label>}
      <button disabled={busy}>{seed?'Confirm authenticator':status.enrolled?'Verify authenticator':'Begin authenticator enrollment'}</button></form>
      {status.enrolled&&!seed&&<form onSubmit={submit} aria-busy={busy}><h3>Use a recovery code</h3><input name="action" type="hidden" value="mfa/recovery"/>{password}<label>Recovery code<input name="code" type="password" required minLength={32} maxLength={32} autoComplete="off"/></label><button disabled={busy}>Verify recovery code</button></form>}
    </section>}
    {identity&&<section><h2>Verified platform identity</h2><p>Identifier: {identity.user_id}</p><p>Context: {identity.context}</p><p>Supported capability: {identity.capabilities.join(', ')}</p><button disabled={busy} onClick={()=>refresh().catch(failure)}>Refresh identity</button></section>}
    {codes.length>0&&<section aria-label="Platform recovery codes"><h2>Save recovery codes offline</h2><p>Shown once. Each code can be consumed once.</p><ul>{codes.map(c=><li key={c}>{c}</li>)}</ul><button onClick={()=>setCodes([])}>Dismiss codes</button></section>}
    </>}
    {busy&&<p role="status">Checking platform security…</p>}{error&&<p role="alert">{error}</p>}
    {(status||unavailable||expired)&&<button disabled={busy} onClick={signOut}>Sign out of platform</button>}
  </main>;
}
