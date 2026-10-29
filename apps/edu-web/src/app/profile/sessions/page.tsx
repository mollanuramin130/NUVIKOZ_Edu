'use client';
import { useEffect, useState, type FormEvent } from 'react';
import { AuthError, browserRequest } from '../../../lib/browser-auth';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
type Session = {id:string;current:boolean;last_activity:number};
type Device = {id:string;current:boolean;name:string;expires_at:string|null};
export default function Sessions(){
 const [sessions,setSessions]=useState<Session[]>([]);const [devices,setDevices]=useState<Device[]>([]);const [busy,setBusy]=useState(true);const [error,setError]=useState('');const router=useRouter();
 function failure(e:unknown){if(e instanceof AuthError&&e.status===401)router.replace('/session-expired');else if(e instanceof AuthError&&e.code==='MFA_REQUIRED')router.replace('/profile/security');else setError(e instanceof Error?e.message:'Security records unavailable.');}
 async function load(){const [s,d]=await Promise.all([browserRequest<{sessions:Session[]}>('security/sessions'),browserRequest<{devices:Device[]}>('security/devices')]);setSessions(s.sessions);setDevices(d.devices);}
 useEffect(()=>{let active=true;Promise.all([browserRequest<{sessions:Session[]}>('security/sessions'),browserRequest<{devices:Device[]}>('security/devices')]).then(([s,d])=>{if(active){setSessions(s.sessions);setDevices(d.devices);setBusy(false);}}).catch(e=>{if(active){if(e instanceof AuthError&&e.status===401)router.replace('/session-expired');else if(e instanceof AuthError&&e.code==='MFA_REQUIRED')router.replace('/profile/security');else setError(e instanceof Error?e.message:'Security records unavailable.');setBusy(false);}});return()=>{active=false;};},[router]);
 async function revoke(event:FormEvent<HTMLFormElement>){event.preventDefault();const form=event.currentTarget;const data=new FormData(form);const action=String(data.get('action'));if(!window.confirm('Revoke the selected owned session or device? Sign-in will be required there.'))return;setBusy(true);setError('');try{await browserRequest(`security/${action}/revoke`,{password:data.get('password')});form.reset();if(action.startsWith('sessions/')&&sessions.find(s=>s.id===action.split('/')[1])?.current){router.replace('/login');return;}await load();}catch(e){failure(e);}finally{setBusy(false);}}
 function control(action:string,label:string){return <form onSubmit={revoke} aria-busy={busy}><input name="action" type="hidden" value={action}/><label>Current password<input name="password" type="password" required maxLength={72} autoComplete="current-password"/></label><button disabled={busy}>{label}</button></form>;}
 return <main className="auth-card"><h1>Sessions and devices</h1><p>Only your own sessions and devices are shown. Browser sessions are identity-wide; devices are restricted to this school context. Session start/absolute-expiry metadata is not recorded in this inventory; server expiry checks remain authoritative.</p>
 {error&&<p role="alert">{error}</p>}{busy&&<p role="status">Loading security records…</p>}
 <section><h2>Browser sessions</h2>{sessions.length===0?<p>No recorded sessions.</p>:sessions.map(s=><article key={s.id}><h3>{s.current?'Current browser':'Other browser session'}</h3><p>Last activity: {new Date(s.last_activity*1000).toLocaleString()}</p>{control(`sessions/${s.id}`,s.current?'Revoke current browser':'Revoke browser session')}</article>)}{control('sessions/others','Revoke other browser sessions')}</section>
 <section><h2>Devices in this school</h2>{devices.length===0?<p>No registered device tokens.</p>:devices.map(d=><article key={d.id}><h3>{d.name||'Device'}{d.current?' (current)':''}</h3><p>Expires: {d.expires_at?new Date(d.expires_at).toLocaleString():'Unavailable'}</p>{control(`devices/${d.id}`,'Revoke device')}</article>)}{control('devices/others','Revoke other devices')}</section><Link href="/login">Sign in</Link>
 </main>;
}
