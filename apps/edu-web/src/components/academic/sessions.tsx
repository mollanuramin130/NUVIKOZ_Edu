'use client';
import {useEffect, useRef, useState, type FormEvent} from 'react';
import {academicError, useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, Submit} from './controls';
import {AuthError, browserRequest} from '../../lib/browser-auth';
import {editAcademicSession, newAcademicIntent, type AcademicSession, type Inputs, type SchoolCalendar} from '../../lib/academic-api';
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

function SessionEditor({session, data, close, saved}: {
  session: AcademicSession; data: ReturnType<typeof useAcademicList<'sessions'>>;
  close: () => void; saved: (message: string) => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const pending = useRef<AbortController | null>(null);
  const intent = useRef<{payload: string; key: string} | null>(null);
  const [baseline, setBaseline] = useState(session);
  const [draft, setDraft] = useState<Inputs['sessions']>({name: session.name, starts_on: session.starts_on, ends_on: session.ends_on});
  const [review, setReview] = useState(false);
  const [mustRefresh, setMustRefresh] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const {failure} = useAcademicAccess();
  useEffect(() => {
    dialog.current?.showModal();
    return () => pending.current?.abort();
  }, []);
  const latest = data.items.find(item => item.id === session.id);
  const today = data.calendar?.today;
  const eligible = latest?.state === 'future' && today && latest.starts_on > today && data.calendar?.can_write;
  const rebase = latest && latest.version !== baseline.version;

  function dismiss() {
    dialog.current?.close();
    close();
  }

  function preview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!eligible || mustRefresh || rebase) return;
    setError('');
    if (draft.starts_on <= today! || draft.ends_on < draft.starts_on || !draft.name.trim()) {
      setFields({
        ...(draft.starts_on <= today! ? {starts_on: ['Choose a start after the school-local date.']} : {}),
        ...(draft.ends_on < draft.starts_on ? {ends_on: ['Choose an end on or after the start.']} : {}),
        ...(!draft.name.trim() ? {name: ['Enter a session name.']} : {}),
      });
      return;
    }
    setFields({}); setReview(true);
  }

  async function confirm() {
    if (pending.current || !eligible || mustRefresh || rebase) return;
    const body = {...draft, name: draft.name.trim(), version: baseline.version};
    const payload = JSON.stringify(body);
    if (intent.current?.payload !== payload) intent.current = {payload, key: newAcademicIntent()};
    const request = new AbortController();
    pending.current = request;
    setBusy(true); setError('');
    try {
      const result = await editAcademicSession(session.id, body, intent.current.key, request.signal);
      if (!request.signal.aborted) {
        dialog.current?.close();
        saved(result.replayed ? 'The saved session edit was confirmed.' : 'Future session updated.');
      }
    } catch (e) {
      if (request.signal.aborted) return;
      if (e instanceof AuthError && e.status === 409) {
        setError(e.message);
        setMustRefresh(true);
        setReview(false);
      } else if (e instanceof AuthError && e.status === 422) {
        setError(e.fields.session?.[0] ?? academicError(e)); setFields(e.fields); setReview(false);
      } else {
        setError(academicError(e)); failure(e);
      }
    } finally {
      pending.current = null;
      if (!request.signal.aborted) setBusy(false);
    }
  }

  return <dialog ref={dialog} aria-label="Edit future session" style={{maxWidth: 'min(32rem, calc(100vw - 2rem))'}} onCancel={event => {
    event.preventDefault();
    if (!pending.current) dismiss();
  }}>
    <h2>{review ? 'Confirm future session changes' : 'Edit future session'}</h2>
    {error && <p role="alert">{error}</p>}
    {data.error && <p role="alert">{data.error}</p>}
    {data.busy && <p role="status">Refreshing the session calendar…</p>}
    {!eligible && !data.busy && <p role="status">Editing requires write access, a school timezone, and a future session whose start is after today.</p>}
    {rebase && latest && <p role="status">Current session: {latest.name}, {latest.starts_on} to {latest.ends_on} (version {latest.version}). Review these values before proceeding with your draft.</p>}
    {review ? <>
      <p>{baseline.name} → {draft.name.trim()}</p>
      <p>{baseline.starts_on} to {baseline.ends_on} → {draft.starts_on} to {draft.ends_on} · {data.calendar?.timezone}</p>
      <p>Date changes apply to the calendar of any linked class offerings. The offerings and their historical references are preserved.</p>
      <button type="button" disabled={busy} onClick={() => setReview(false)}>Back to edit</button>
      <button type="button" disabled={busy || !eligible || mustRefresh || rebase} onClick={() => void confirm()}>{busy ? 'Saving…' : 'Confirm edit'}</button>
    </> : <form onSubmit={preview}>
      <Field label="Session name" name="name" errors={fields}><input required maxLength={60} value={draft.name} onChange={e => setDraft({...draft, name: e.target.value})}/></Field>
      <Field label="Start date" name="starts_on" errors={fields}><input required type="date" value={draft.starts_on} onChange={e => setDraft({...draft, starts_on: e.target.value})}/></Field>
      <Field label="End date" name="ends_on" errors={fields}><input required type="date" value={draft.ends_on} onChange={e => setDraft({...draft, ends_on: e.target.value})}/></Field>
      {rebase && eligible && <button type="button" onClick={() => {setBaseline(latest!); setError('');}}>Use current version with my draft</button>}
      <button type="submit" disabled={!eligible || mustRefresh || rebase}>Review changes</button>
    </form>}
    <button type="button" disabled={busy || data.busy} onClick={() => {setReview(false); data.refreshPage(); setMustRefresh(false);}}>Refresh sessions</button>
    <button type="button" disabled={busy} onClick={dismiss}>Cancel edit</button>
  </dialog>;
}

export default function Sessions() {
  const data = useAcademicList('sessions'); const form = useRef<HTMLFormElement>(null);
  const [editing, setEditing] = useState<AcademicSession>();
  const [editMessage, setEditMessage] = useState('');
  const command = useAcademicCommand('sessions', () => {form.current?.reset(); data.refresh();});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); const f = new FormData(e.currentTarget); void command.submit({name: String(f.get('name')).trim(), starts_on: String(f.get('starts_on')), ends_on: String(f.get('ends_on'))});}
  return <><PageHeading title="Academic sessions" section="Sessions" description="Create future calendars while preserving every historical academic reference."/>
    <Feedback error="" message={editMessage}/>
    {editing && <SessionEditor session={editing} data={data} close={() => setEditing(undefined)} saved={message => {setEditMessage(message); setEditing(undefined); data.refreshPage();}}/>}
    <div className="ac-split"><Panel title="Session calendar" description="Calendar dates belong to the school, not your browser timezone."><RecordList data={data} label="Sessions" columns={['Session', 'Date range', 'State', 'Lifecycle']} searchable={r => r.name} empty="Create a future session to begin organizing your classes." row={r => [<strong key="name">{r.name}</strong>, <span key="dates">{r.starts_on} <span className="ac-muted">to</span> {r.ends_on}</span>, <span key="state" className="ac-pill">{r.state}</span>, <div key={r.id + r.version}><SessionTransition session={r} calendar={data.calendar} refresh={data.refresh}/>{r.state === 'future' && (data.calendar?.today && data.calendar.can_write && r.starts_on > data.calendar.today ? <button type="button" onClick={() => {setEditMessage(''); setEditing(r);}}>Edit session</button> : <small>Editing requires write access and a start date after the school-local date.</small>)}</div>]}/></Panel>
    <Panel title="Create future session" description="New sessions begin in the future state."><form ref={form} onSubmit={submit} aria-busy={command.busy}><fieldset disabled={command.busy}><Field label="Session name" name="name" errors={command.fields}><input name="name" required maxLength={60} placeholder="e.g. 2030–2031"/></Field><div className="ac-form-row"><Field label="Start date" name="starts_on" errors={command.fields}><input name="starts_on" type="date" required/></Field><Field label="End date" name="ends_on" errors={command.fields}><input name="ends_on" type="date" required/></Field></div><p className="ac-note">The server checks that the start is after today in your configured school timezone, with a valid date range and no future/active overlap. No browser timezone is assumed.</p><Feedback error={command.error} message={command.message} refresh={data.refresh}/><Submit busy={command.busy}>Create session</Submit></fieldset></form></Panel></div>{data.items.filter(session => session.state === 'active').map(session => <ChangeRequest key={session.id + ':' + session.version} session={session}/>)}</>;
}
