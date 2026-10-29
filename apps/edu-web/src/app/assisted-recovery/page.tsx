'use client';
import { useEffect, useState, type FormEvent } from 'react';
import { AuthError, browserRequest, onSessionChange, type Identity } from '../../lib/browser-auth';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
type Proof = { proof_id: string; secret: string; expires_at: string };
export default function AssistedRecovery() {
  const [identity, setIdentity] = useState<Identity | null>(null); const [proof, setProof] = useState<Proof | null>(null);
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const router = useRouter();
  useEffect(() => {
    let active = true; let generation = 0;
    const verify = () => {
      const current = ++generation; setIdentity(null); setProof(null);
      browserRequest<Identity>('me').then(value => { if (!active || current !== generation) return; if (value.role !== 'school_admin') router.replace('/access-denied'); else setIdentity(value); })
        .catch(() => { if (active && current === generation) router.replace('/session-expired'); });
    };
    const unsubscribe = onSessionChange(verify); window.addEventListener('focus', verify); verify();
    return () => { active = false; unsubscribe(); window.removeEventListener('focus', verify); };
  }, [router]);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; const fields = new FormData(form); setBusy(true); setError(''); setProof(null);
    try {
      const result = await browserRequest<{local_handover: Proof}>(`accounts/${encodeURIComponent(String(fields.get('membership_id')))}/recovery`, {
        verification_method: 'in_person_registered_record', verification_reference: fields.get('verification_reference'), identity_verified: fields.get('identity_verified') === 'on',
      }); setProof(result.local_handover); form.reset();
    } catch(e) {
      if(e instanceof AuthError && e.status === 401) { router.replace('/session-expired'); return; }
      setError(e instanceof Error ? e.message : 'Recovery authorization failed.');
    } finally { setBusy(false); }
  }
  if (!identity) return <main><p role="status">Checking School Admin access…</p></main>;
  return <main className="auth-card"><h1>Assisted password recovery</h1><p>School context: {identity.school_id}</p><p>Verify the account holder in person against existing school registration records. Shared phone possession, name or date of birth alone is insufficient. Record verification in the school’s restricted case register; enter only its opaque reference below, never identity documents or personal details.</p>
    <form onSubmit={submit} aria-busy={busy}>
      <label>Target membership ID<input name="membership_id" required minLength={26} maxLength={26} autoComplete="off" /></label>
      <label>Verification case reference<input name="verification_reference" required minLength={16} maxLength={80} pattern="[A-Za-z0-9_-]+" autoComplete="off" /></label>
      <label><input name="identity_verified" type="checkbox" required />I verified this person in person against registered school records and recorded the evidence in the restricted case register.</label>
      {error && <p role="alert">{error}</p>}<button disabled={busy}>{busy ? 'Authorizing…' : 'Authorize one-time recovery'}</button>
    </form>
    {proof && <section aria-label="One-time local recovery handover"><h2>Local testing handover only</h2><p>Hand this proof only to the verified account holder. It expires in 15 minutes. Reissue invalidates earlier proofs.</p><dl><dt>Recovery ID</dt><dd>{proof.proof_id}</dd><dt>Recovery secret</dt><dd style={{overflowWrap:'anywhere'}}>{proof.secret}</dd><dt>Expires</dt><dd>{proof.expires_at}</dd></dl><button onClick={() => setProof(null)}>Dismiss proof</button></section>}
    <Link href="/dashboard/school_admin">Return to dashboard</Link>
  </main>;
}
