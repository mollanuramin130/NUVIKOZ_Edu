'use client';
import {useEffect, useRef, useState, type FormEvent} from 'react';
import {academicError, useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, Submit} from './controls';
import {AuthError, browserRequest} from '../../lib/browser-auth';
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

export default function Sessions() {
  const data = useAcademicList('sessions'); const form = useRef<HTMLFormElement>(null);
  const command = useAcademicCommand('sessions', () => {form.current?.reset(); data.refresh();});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); const f = new FormData(e.currentTarget); void command.submit({name: String(f.get('name')).trim(), starts_on: String(f.get('starts_on')), ends_on: String(f.get('ends_on'))});}
  return <><PageHeading title="Academic sessions" section="Sessions" description="Create future calendars while preserving every historical academic reference."/>
    <div className="ac-split"><Panel title="Session calendar" description="Calendar dates belong to the school, not your browser timezone."><RecordList data={data} label="Sessions" columns={['Session', 'Date range', 'State']} searchable={r => r.name} empty="Create a future session to begin organizing your classes." row={r => [<strong key="name">{r.name}</strong>, <span key="dates">{r.starts_on} <span className="ac-muted">to</span> {r.ends_on}</span>, <span key="state" className="ac-pill">{r.state}</span>]}/></Panel>
    <Panel title="Create future session" description="New sessions begin in the future state."><form ref={form} onSubmit={submit} aria-busy={command.busy}><fieldset disabled={command.busy}><Field label="Session name" name="name" errors={command.fields}><input name="name" required maxLength={60} placeholder="e.g. 2030–2031"/></Field><div className="ac-form-row"><Field label="Start date" name="starts_on" errors={command.fields}><input name="starts_on" type="date" required/></Field><Field label="End date" name="ends_on" errors={command.fields}><input name="ends_on" type="date" required/></Field></div><p className="ac-note">The server checks that the start is after today in your configured school timezone, with a valid date range and no future/active overlap. No browser timezone is assumed.</p><Feedback error={command.error} message={command.message} refresh={data.refresh}/><Submit busy={command.busy}>Create session</Submit></fieldset></form></Panel></div>{data.items.filter(session => session.state === 'active').map(session => <ChangeRequest key={session.id + ':' + session.version} session={session}/>)}</>;
}
