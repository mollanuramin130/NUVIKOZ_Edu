'use client';

import { useRef, useState, type FormEvent } from 'react';
import { AuthError, browserRequest } from './browser-auth';

type StaffDelivery = {
  membership_id?: string;
  sealed_handover?: string;
  replayed?: boolean;
};

export default function StagingStaffAccess() {
  const request = useRef<{ body: string; key: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [envelope, setEnvelope] = useState('');
  const [membership, setMembership] = useState('');

  async function run(action: () => Promise<StaffDelivery>) {
    setBusy(true);
    setMessage('');
    setEnvelope('');
    try {
      const result = await action();
      if (result.membership_id) setMembership(result.membership_id);
      setEnvelope(result.sealed_handover ?? '');
      setMessage(result.sealed_handover ? 'Encrypted activation proof issued.' :
        result.replayed ? 'Account already created for this request. Reissue the proof if needed.' :
          'The server did not return an encrypted activation proof.');
    } catch (error) {
      if (error instanceof AuthError && error.status === 503) {
        setMessage('Staff activation delivery is unavailable for this school.');
      } else if (error instanceof AuthError && [401, 403, 419].includes(error.status)) {
        setMessage('Check your School Admin session and MFA, then retry.');
      } else {
        setMessage('The operation could not be confirmed. Check the input and retry or review School permissions.');
      }
    } finally {
      setBusy(false);
    }
  }

  function provision(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = new FormData(event.currentTarget);
    const body = {
      name: fields.get('name'),
      login_identifier: fields.get('identifier'),
      role: fields.get('role'),
    };
    const serialized = JSON.stringify(body);
    if (request.current?.body !== serialized) {
      request.current = { body: serialized, key: crypto.randomUUID() };
    }
    const { key } = request.current;
    void run(() => browserRequest('accounts', body, { idempotencyKey: key }));
  }

  function reissue(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(membership)) return;
    void run(() => browserRequest(`accounts/${membership}/activation/reissue`, {}));
  }

  return (
    <details>
      <summary>Staging staff accounts</summary>
      <form onSubmit={provision}>
        <fieldset disabled={busy}>
          <legend>Create a synthetic staff account</legend>
          <label>Staff name<input name="name" required maxLength={160} /></label>
          <label>Login ID<input name="identifier" required maxLength={80} pattern={'[A-Za-z0-9_\\-]+'} /></label>
          <label>Staff role<select name="role"><option value="principal">Principal</option><option value="teacher">Teacher</option></select></label>
          <button type="submit">Create staff account</button>
        </fieldset>
      </form>
      <form onSubmit={reissue}>
        <fieldset disabled={busy}>
          <legend>Replace a pending staff activation proof</legend>
          <label>Membership ID<input required pattern="[0-9A-HJKMNP-TV-Z]{26}" value={membership} onChange={event => setMembership(event.target.value)} /></label>
          <button type="submit">Replace proof</button>
        </fieldset>
      </form>
      {message && <p role="status">{message}</p>}
      {envelope && <label>Encrypted activation proof<textarea readOnly value={envelope} rows={4} /></label>}
    </details>
  );
}
