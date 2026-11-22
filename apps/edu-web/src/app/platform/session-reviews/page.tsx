'use client';

import Link from 'next/link';
import {useEffect, useRef, useState, type FormEvent} from 'react';
import {AuthError, browserRequest, onSessionChange} from '../../../lib/browser-auth';

const path = 'platform/sessions/change-requests';
type Item = {id: string; version: number; created_at: string; school_id: string; school_name: string; school_code: string;
  session_id: string; session_name: string; proposed_start: string; proposed_end: string};
type Dates = {starts_on: string; ends_on: string};
type Preview = {id: string; version: number; state: string; reason: string; school: {name: string; code: string};
  submitted: {dates: Dates; class_offerings: number}; session: {name: string; dates: Dates; class_offerings: number; state: string; version: number};
  proposed: Dates; timezone: string | null; local_date: string | null; blockers: string[]; can_approve: boolean; preview_token: string | null};
type Filters = {school_id?: string; q?: string};
const explanations: Record<string, string> = {
  REQUEST_RESOLVED: 'Another reviewer has already resolved this request.',
  SESSION_NOT_ACTIVE: 'The session is no longer active.',
  SESSION_VERSION_CHANGED: 'The session changed after submission.',
  IMPACT_CHANGED: 'The dates or linked offering count changed after submission.',
  OVERLAP: 'The proposed period overlaps another session.',
  SCHOOL_WRITE_DISABLED: 'The school does not currently have write access.',
  INVALID_SCHOOL_TIMEZONE: 'The school timezone is missing or invalid.',
  OUTSIDE_LOCAL_RANGE: 'The proposed period excludes the school’s current date.',
};
const range = (dates: Dates) => `${dates.starts_on} to ${dates.ends_on}`;

export default function SessionReviews() {
  const [items, setItems] = useState<Item[]>([]);
  const [next, setNext] = useState<string | null>(null);
  const [filters, setFilters] = useState<Filters>({});
  const [cursors, setCursors] = useState<(string | undefined)[]>([undefined]);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [reason, setReason] = useState('');
  const [decision, setDecision] = useState<'approve' | 'reject' | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [denied, setDenied] = useState(false);
  const [stale, setStale] = useState(false);
  const gate = useRef<AbortController | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const intent = useRef<{body: string; key: string} | null>(null);

  function clearProtected() {
    gate.current?.abort();
    gate.current = null;
    intent.current = null;
    dialog.current?.close();
    setItems([]); setNext(null); setFilters({}); setCursors([undefined]); setPreview(null); setReason(''); setDecision(null); setStale(false);
    setMessage(''); setError(''); setBusy(false); setDenied(true);
  }

  async function perform(work: (signal: AbortSignal) => Promise<void>, mutation = false) {
    if (gate.current) return;
    const controller = new AbortController();
    gate.current = controller;
    setBusy(true); setError('');
    try {
      await work(controller.signal);
    } catch (e) {
      if (controller.signal.aborted) return;
      if (e instanceof AuthError && [401, 403, 419].includes(e.status)) {
        if (mutation && e.status === 403) {
          try {
            await browserRequest('platform/identity', undefined, {signal: controller.signal});
            if (!controller.signal.aborted) {
              dialog.current?.close(); setDecision(null); setStale(true);
              setError('Approval is no longer available. Refresh the preview before continuing.');
            }
            return;
          } catch {
            if (controller.signal.aborted) return;
          }
        }
        clearProtected();
      } else if (mutation && e instanceof AuthError && e.status === 409) {
        dialog.current?.close(); setDecision(null); setStale(true);
        setError('The preview or request changed. Refresh it and confirm again.');
      } else {
        setError('The request could not be completed. Review the current details and retry.');
      }
    } finally {
      if (gate.current === controller) {gate.current = null; setBusy(false);}
    }
  }

  function load(query: Filters, history: (string | undefined)[]) {
    return perform(async signal => {
      const page = await browserRequest<{items: Item[]; next_cursor: string | null}>(path, undefined, {
        query: {...query, cursor: history.at(-1)}, signal,
      });
      if (signal.aborted) return;
      setFilters(query); setCursors(history); setItems(page.items); setNext(page.next_cursor); setDenied(false);
    });
  }

  useEffect(() => {
    let mounted = true;
    void Promise.resolve().then(() => {if (mounted) void load({}, [undefined]);});
    const unsubscribe = onSessionChange(clearProtected);
    // Revalidate without refreshing the idle window. An in-flight mutation must not delay invalidation.
    const check = async () => {
      try {
        const status = await browserRequest<{verified: boolean}>('platform/status');
        if (mounted && !status.verified) clearProtected();
      } catch {if (mounted) clearProtected();}
    };
    const timer = window.setInterval(() => {void check();}, 30_000);
    window.addEventListener('focus', check);
    return () => {
      mounted = false;
      gate.current?.abort(); gate.current = null;
      unsubscribe(); window.clearInterval(timer); window.removeEventListener('focus', check);
    };
    // Initial load and session invalidation callbacks only use refs and stable state setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const school = String(data.get('school_id')).trim();
    const q = String(data.get('q')).trim();
    if (q.length === 1) {setError('Enter at least two search characters.'); return;}
    setPreview(null); setReason(''); intent.current = null;
    void load({school_id: school || undefined, q: q || undefined}, [undefined]);
  }

  function open(id: string, refresh = false) {
    void perform(async signal => {
      const result = await browserRequest<Preview>(`${path}/${id}/preview`, undefined, {signal});
      if (signal.aborted) return;
      setPreview(result); setStale(false); intent.current = null;
      if (!refresh) {setReason(''); setMessage('');}
    });
  }

  function begin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!preview || stale || preview.state !== 'pending' || reason.trim().length < 3) return;
    const button = (event.nativeEvent as SubmitEvent).submitter;
    const action = button?.getAttribute('value');
    if (action !== 'approve' && action !== 'reject') return;
    setDecision(action); dialog.current?.showModal();
  }

  function submit() {
    if (!decision || !preview || stale) return;
    const body = {decision, reason: reason.trim(), version: preview.version,
      ...(decision === 'approve' ? {preview_token: preview.preview_token} : {})};
    const serialized = JSON.stringify(body);
    if (intent.current?.body !== serialized) intent.current = {body: serialized, key: crypto.randomUUID()};
    const key = intent.current.key;
    void perform(async signal => {
      const result = await browserRequest<{state: string}>(`${path}/${preview.id}/review`, body, {idempotencyKey: key, signal});
      if (signal.aborted) return;
      dialog.current?.close(); setDecision(null); setPreview(null); setReason(''); intent.current = null;
      setItems(rows => rows.filter(row => row.id !== preview.id));
      setMessage(`Request ${result.state}.`);
    }, true);
  }

  return <main aria-busy={busy} className="auth-card" style={{width: 'min(100%, 64rem)', maxWidth: '64rem'}}>
    <p><Link href="/platform">Platform security</Link></p>
    <h1>Session date-change reviews</h1>
    {denied ? <p role="alert">Platform authorization expired or is unavailable. Return to Platform security to verify your session.</p> : <>
      <form onSubmit={search} aria-label="Filter pending requests">
        <label>Search<input name="q" maxLength={80}/></label>
        <label>School ID<input name="school_id" pattern="[0-9A-Ha-hJ-Kj-kM-Nm-nP-Tp-tV-Zv-z]{26}" maxLength={26}/></label>
        <button disabled={busy}>Apply filters</button>
        <button type="button" disabled={busy} onClick={() => void load(filters, [undefined])}>Refresh queue</button>
      </form>
      <div style={{overflowX: 'auto'}} role="region" aria-label="Pending requests" tabIndex={0}>
        <table><caption>Pending requests</caption><thead><tr><th scope="col">School</th><th scope="col">Session</th><th scope="col">Submitted</th><th scope="col">Proposed dates</th><th scope="col">Action</th></tr></thead>
          <tbody>{items.map(item => <tr key={item.id}><td>{item.school_name} · {item.school_code}<br/><small>{item.school_id}</small></td><td>{item.session_name}</td>
            <td>{item.created_at}</td><td>{item.proposed_start} to {item.proposed_end}</td><td><button disabled={busy} type="button" onClick={() => open(item.id)}>Preview {item.session_name}</button></td></tr>)}</tbody></table>
      </div>
      {!busy && items.length === 0 && <p>No pending requests on this page.</p>}
      <button type="button" disabled={busy || cursors.length < 2} onClick={() => void load(filters, cursors.slice(0, -1))}>Previous page</button>
      <button type="button" disabled={busy || !next} onClick={() => next && void load(filters, [...cursors, next])}>Next page</button>
      {preview && <section aria-label="Review preview">
        <h2>{preview.school.name} · {preview.session.name}</h2>
        <p>Request {preview.state}. Session {preview.session.state}, version {preview.session.version}.</p>
        <dl><dt>At submission</dt><dd>{range(preview.submitted.dates)} · {preview.submitted.class_offerings} linked offerings</dd>
          <dt>Current</dt><dd>{range(preview.session.dates)} · {preview.session.class_offerings} linked offerings</dd>
          <dt>Proposed</dt><dd>{range(preview.proposed)}</dd>
          <dt>School-local date</dt><dd>{preview.local_date ?? 'Unavailable'} · {preview.timezone ?? 'Unavailable'}</dd>
          <dt>Applicant reason</dt><dd style={{overflowWrap: 'anywhere', whiteSpace: 'pre-wrap'}}>{preview.reason}</dd></dl>
        {preview.blockers.length > 0 && <ul>{preview.blockers.map(blocker => <li key={blocker}>{explanations[blocker] ?? 'Approval is unavailable.'}</li>)}</ul>}
        <button type="button" disabled={busy} onClick={() => open(preview.id, true)}>Refresh preview</button>
        {preview.state === 'pending' && <form onSubmit={begin}>
          <label>Reviewer reason<textarea required minLength={3} maxLength={2000} value={reason} onChange={event => setReason(event.target.value)}/></label>
          <button value="approve" disabled={busy || stale || !preview.can_approve}>Approve</button>
          <button value="reject" disabled={busy || stale}>Reject</button>
        </form>}
      </section>}
      {busy && <p role="status">Working…</p>}{error && <p role="alert">{error}</p>}{message && <p role="status">{message}</p>}
      <dialog ref={dialog} aria-labelledby="decision-title" onCancel={event => {if (busy) event.preventDefault(); else setDecision(null);}}>
        {decision && preview && <><h2 id="decision-title">{decision === 'approve' ? 'Confirm approval' : 'Confirm rejection'}</h2>
          <p>{preview.school.name} · {preview.session.name}</p>
          <p>{decision === 'approve' ? `${range(preview.session.dates)} → ${range(preview.proposed)} · ${preview.session.class_offerings} linked offerings` : 'Rejection records the decision without changing the session dates.'}</p>
          {error && <p role="alert">{error}</p>}
          <button type="button" disabled={busy} onClick={() => {dialog.current?.close(); setDecision(null);}}>Cancel</button>
          <button type="button" disabled={busy} onClick={submit}>Confirm {decision === 'approve' ? 'approval' : 'rejection'}</button></>}
      </dialog>
    </>}
  </main>;
}
