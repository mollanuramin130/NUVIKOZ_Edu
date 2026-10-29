'use client';
import {useState,type FormEvent} from 'react';
import {browserRequest} from '../lib/browser-auth';
export function MfaReplacement({context,onComplete}:{context:'school'|'platform';onComplete:(codes:string[])=>void}){
 const [pending,setPending]=useState<{replacement_id:string;secret:string}|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 async function submit(event:FormEvent<HTMLFormElement>){event.preventDefault();const form=event.currentTarget,data=new FormData(form);setBusy(true);setError('');
  try{const prefix=context==='platform'?'platform/mfa':'security/mfa';
   if(pending){const result=await browserRequest<{recovery_codes:string[];reauthentication_required:boolean}>(`${prefix}/replacement/confirm`,{password:data.get('password'),replacement_id:pending.replacement_id,code:data.get('code')});form.reset();setPending(null);onComplete(result.recovery_codes);}
   else{const result=await browserRequest<{replacement_id:string;secret:string}>(`${prefix}/replacement/start`,{password:data.get('password'),method:data.get('method'),code:data.get('code')});form.reset();setPending(result);}
  }catch(e){setError(e instanceof Error?e.message:'Replacement failed. The existing authenticator remains active until confirmation.');}finally{setBusy(false);}
 }
 return <section aria-label="Authenticator replacement"><h2>Replace your authenticator</h2><p>Requires your password and an existing authenticator or unused recovery code. Password-only lost-factor reset is unavailable.</p>
 {pending&&<><p>Use SHA-1, six digits, 30-second periods. Confirm this new secret within five minutes. The old authenticator stays active until confirmation.</p><p data-testid="replacement-seed" style={{overflowWrap:'anywhere'}}>{pending.secret}</p></>}
 <form onSubmit={submit} aria-busy={busy}><label>Password for replacement<input name="password" type="password" required maxLength={72} autoComplete="current-password"/></label>
 {!pending&&<label>Existing factor type<select name="method" defaultValue="totp"><option value="totp">Authenticator</option><option value="recovery">Recovery code</option></select></label>}
 <label>{pending?'New authenticator code':'Existing factor code'}<input name="code" type="password" required maxLength={pending?6:32} minLength={pending?6:6} autoComplete="one-time-code"/></label>
 <button disabled={busy}>{pending?'Confirm replacement':'Begin authenticator replacement'}</button></form>
 <p>Successful replacement rotates recovery codes and signs out all existing browser/device sessions. Fresh authentication is required.</p>{error&&<p role="alert">{error}</p>}
 </section>;
}
