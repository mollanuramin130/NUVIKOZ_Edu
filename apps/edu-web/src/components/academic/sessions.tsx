'use client';
import {useEffect, useRef, useState, type FormEvent} from 'react';
import {academicError, useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, Submit} from './controls';
import {AuthError, browserRequest} from '../../lib/browser-auth';
import type {AcademicSession, SchoolCalendar} from '../../lib/academic-api';
import {useAcademicAccess} from './workspace';

function ChangeRequest({session}: {session: {id: string; name: string; starts_on: string; ends_on: string; version: number}}) {
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const pending = useRef<AbortController | null>(null);
  const intent = useRef<{payload: string; key: string} | null>(null);
  const {failure} = useAcademicAccess();
  useEffect(() => () => pending.current?.abort(), []);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (pending.current) return;
    const data = new FormData(e.currentTarget);
    const body = {proposed_start: String(data.get('proposed_start')), proposed_end: String(data.get('proposed_end')), reason: String(data.get('reason')).trim(), version: session.version};
    setMessage(''); setError(''); setFields({});
    if (body.proposed_end < body.proposed_start) {
      setFields({proposed_end: ['End date must be on or after the start date.']});
      return;
    }
    const payload = JSON.stringify(body);
    if (intent.current?.payload !== payload) intent.current = {payload, key: crypto.randomUUID()};
    const request = new AbortController();
    pending.current = request; setBusy(true);
    try {
      const result = await browserRequest<{id: string; state: string; impact: {class_offerings: number}}>(`sessions/${session.id}/change-requests`, body, {idempotencyKey: intent.current.key, signal: request.signal});
      if (!request.signal.aborted) setMessage(`Request ${result.id} · ${result.state} · ${result.impact.class_offerings} linked class offerings.`);
    } catch (e) {
      if (!request.signal.aborted) {
        setError(academicError(e));
        if (e instanceof AuthError) setFields(e.fields);
        failure(e);
      }
    } finally {pending.current = null; if (!request.signal.aborted) setBusy(false);}
  }
  return <Panel title={`Request active date changes · ${session.name}`} description="The current calendar stays in effect during Platform review.">
    <form onSubmit={submit} aria-busy={busy}><fieldset disabled={busy}>
      <div className="ac-form-row">
        <Field label="Proposed start" name="proposed_start" errors={fields}><input type="date" name="proposed_start" defaultValue={session.starts_on} required/></Field>
        <Field label="Proposed end" name="proposed_end" errors={fields}><input type="date" name="proposed_end" defaultValue={session.ends_on} required/></Field>
      </div>
      <Field label="Reason for change" name="reason" errors={fields}><textarea name="reason" required minLength={3} maxLength={2000}/></Field>
      {error && <p role="alert">{error}</p>}{message && <p role="status">{message}</p>}
      <Submit busy={busy}>Submit for Platform review</Submit>
    </fieldset></form>
  </Panel>;
}

function SessionTransition({session, calendar, refresh}: {session: AcademicSession; calendar?: SchoolCalendar; refresh: () => void}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const pending = useRef<AbortController | null>(null);
  const key = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const {failure} = useAcademicAccess();
  useEffect(() => () => pending.current?.abort(), []);
  if (session.state !== 'future' && session.state !== 'active') return null;
  const action = session.state === 'future' ? 'activate' : 'close';
  const label = action === 'activate' ? 'Activate' : 'Close';
  const today = calendar?.today;
  let blocker = '';
  if (!today) blocker = 'A valid school timezone is required.';
  else if (action === 'activate' && today > session.ends_on) blocker = 'Activation window missed. Recovery requires a separately approved policy.';
  else if (action === 'close' && !calendar.can_write) blocker = 'Closure requires current school write entitlement. No override is available.';
  else if (!calendar.can_write) blocker = 'Activation requires current school write entitlement.';
  else if (action === 'activate' && today < session.starts_on) blocker = 'Activation opens on the school-local start date.';
  else if (action === 'activate' && calendar.has_active_session) blocker = 'Close the existing active session first.';
  else if (action === 'close' && today <= session.ends_on) blocker = 'Closure opens after the school-local end date.';

  async function confirm() {
    if (pending.current || blocker) return;
    const request = new AbortController();
    pending.current = request;
    key.current ??= crypto.randomUUID();
    setBusy(true); setError('');
    try {
      await browserRequest(`academic/sessions/${session.id}/transitions`, {action, version: session.version}, {idempotencyKey: key.current, signal: request.signal});
      if (!request.signal.aborted) {dialog.current?.close(); refresh();}
    } catch (e) {
      if (!request.signal.aborted) {
        setError(e instanceof AuthError && e.status === 409 ? 'The session or its eligibility changed. Refresh sessions before retrying.' : academicError(e));
        failure(e);
      }
    } finally {pending.current = null; if (!request.signal.aborted) setBusy(false);}
  }

  return <div>{blocker ? <small>{blocker}</small> : <button type="button" onClick={() => {setError(''); dialog.current?.showModal();}}>{label}</button>}
    <dialog ref={dialog} aria-label={`${label} ${session.name}`} onCancel={event => {if (busy) event.preventDefault();}}>
      <h2>{label} {session.name}?</h2>
      <p>{session.starts_on} to {session.ends_on} · {calendar?.timezone}</p>
      <p>New state: {action === 'activate' ? 'active' : 'closed'}.</p>
      {error && <p role="alert">{error}</p>}
      <button type="button" disabled={busy} onClick={() => dialog.current?.close()}>Cancel</button>
      <button type="button" disabled={busy} onClick={() => {dialog.current?.close(); refresh();}}>Refresh sessions</button>
      <button type="button" disabled={busy || Boolean(blocker)} onClick={() => void confirm()}>{busy ? 'Saving…' : `Confirm ${label.toLowerCase()}`}</button>
    </dialog>
  </div>;
}

export default function Sessions() {
  const data = useAcademicList('sessions'); const form = useRef<HTMLFormElement>(null);
  const command = useAcademicCommand('sessions', () => {form.current?.reset(); data.refresh();});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); const f = new FormData(e.currentTarget); void command.submit({name: String(f.get('name')).trim(), starts_on: String(f.get('starts_on')), ends_on: String(f.get('ends_on'))});}
  return <><PageHeading title="Academic sessions" section="Sessions" description="Create future calendars while preserving every historical academic reference."/>
    <div className="ac-split"><Panel title="Session calendar" description="Calendar dates belong to the school, not your browser timezone."><RecordList data={data} label="Sessions" columns={['Session', 'Date range', 'State', 'Lifecycle']} searchable={r => r.name} empty="Create a future session to begin organizing your classes." row={r => [<strong key="name">{r.name}</strong>, <span key="dates">{r.starts_on} <span className="ac-muted">to</span> {r.ends_on}</span>, <span key="state" className="ac-pill">{r.state}</span>, <SessionTransition key={r.id + r.version} session={r} calendar={data.calendar} refresh={data.refresh}/>]}/></Panel>
    <Panel title="Create future session" description="New sessions begin in the future state."><form ref={form} onSubmit={submit} aria-busy={command.busy}><fieldset disabled={command.busy}><Field label="Session name" name="name" errors={command.fields}><input name="name" required maxLength={60} placeholder="e.g. 2030–2031"/></Field><div className="ac-form-row"><Field label="Start date" name="starts_on" errors={command.fields}><input name="starts_on" type="date" required/></Field><Field label="End date" name="ends_on" errors={command.fields}><input name="ends_on" type="date" required/></Field></div><p className="ac-note">The server checks that the start is after today in your configured school timezone, with a valid date range and no future/active overlap. No browser timezone is assumed.</p><Feedback error={command.error} message={command.message} refresh={data.refresh}/><Submit busy={command.busy}>Create session</Submit></fieldset></form></Panel></div>{data.items.filter(session => session.state === 'active').map(session => <ChangeRequest key={session.id + ':' + session.version} session={session}/>)}</>;
}
