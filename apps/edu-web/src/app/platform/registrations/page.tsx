'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { AuthError, browserRequest, onSessionChange } from '../../../lib/browser-auth';
import '../../onboarding.css';

type State = 'submitted' | 'reviewing' | 'approved' | 'rejected';
type Decision = Exclude<State, 'submitted'>;
type Row = { id: string; organization_name: string; category: string; state: State; submitted_at: string };
type Page = { items: Row[]; next_cursor: string | null };
type Detail = {
  registration: Row & { contact_name: string; contact: string; notice_version: string };
  history: { id: string; actor_user_id: string; from_state: State; to_state: State; created_at: string; note: string }[];
};

function message(error: unknown) {
  if (error instanceof AuthError) {
    if ([401, 403, 419].includes(error.status)) return 'Sign in and verify platform MFA to review applications.';
    if (error.status === 409) return 'This application has changed. Reopen it before making another decision.';
  }
  return 'The request could not be completed. Try again.';
}

export default function ReviewQueue() {
  const [page, setPage] = useState<Page>();
  const [filters, setFilters] = useState<{ search?: string; state?: string }>({});
  const [detail, setDetail] = useState<Detail>();
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState('');
  const [command, setCommand] = useState<{ state: Decision; note: string; command_id: string }>();
  const pending = useRef<AbortController | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  const load = useCallback(async (query: { search?: string; state?: string; cursor?: string }) => {
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true);
    setError('');
    setDetail(undefined);
    setPage(undefined);
    try {
      const data = await browserRequest<Page>('platform/registrations', undefined, { query, signal: request.signal });
      if (!request.signal.aborted) setPage(data);
    } catch (failure) {
      if (!request.signal.aborted) setError(message(failure));
    } finally {
      if (!request.signal.aborted) setBusy(false);
    }
  }, []);

  useEffect(() => {
    const unsubscribe = onSessionChange(() => {
      dialog.current?.close();
      setCommand(undefined);
      setFeedback('');
      void load({});
    });
    let active = true;
    queueMicrotask(() => { if (active) void load({}); });
    return () => { active = false; unsubscribe(); pending.current?.abort(); };
  }, [load]);

  async function open(id: string) {
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true); setError(''); setFeedback(''); setDetail(undefined);
    try {
      const data = await browserRequest<Detail>(`platform/registrations/${id}`, undefined, { signal: request.signal });
      if (!request.signal.aborted) setDetail(data);
    } catch (failure) {
      if (!request.signal.aborted) setError(message(failure));
    } finally { if (!request.signal.aborted) setBusy(false); }
  }

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const query = { search: String(data.get('search') ?? '').trim() || undefined, state: String(data.get('state') ?? '') || undefined };
    if (query.search?.length === 1) { setError('Use at least two characters to search.'); return; }
    setFilters(query);
    void load(query);
  }

  function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const note = String(data.get('note') ?? '').trim();
    if (!note) { setError('Enter a review note.'); return; }
    setError('');
    setCommand({ state: data.get('state') as Decision, note, command_id: crypto.randomUUID() });
    dialog.current?.showModal();
  }

  async function commit() {
    if (!detail || !command || busy) return;
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true); setError('');
    try {
      await browserRequest(`platform/registrations/${detail.registration.id}/review`, command, { signal: request.signal });
      if (request.signal.aborted) return;
      dialog.current?.close();
      setCommand(undefined);
      setFeedback('Review decision recorded.');
      await load(filters);
    } catch (failure) {
      if (!request.signal.aborted) {
        setError(message(failure));
        if (failure instanceof AuthError && [401, 403, 409, 419].includes(failure.status)) {
          dialog.current?.close();
          setDetail(undefined);
          setCommand(undefined);
        }
      }
    } finally { if (!request.signal.aborted) setBusy(false); }
  }

  const state = detail?.registration.state;
  const reviewable = state === 'submitted' || state === 'reviewing';
  return <main className="onboard">
    <nav className="onboard-nav" aria-label="Platform"><Link href="/platform">Platform security</Link></nav>
    <div className="onboard-workspace">
      <h1>School applications</h1>
      <section className="onboard-card" aria-label="Application queue">
        <form className="onboard-filters" onSubmit={search}>
          <div><label htmlFor="search">School name</label><input id="search" name="search" maxLength={80} /></div>
          <div><label htmlFor="state">Application status</label><select id="state" name="state">
            <option value="">All statuses</option>{['submitted', 'reviewing', 'approved', 'rejected'].map(value => <option key={value}>{value}</option>)}
          </select></div><button disabled={busy}>Filter</button>
        </form>
        {feedback && <p role="status">{feedback}</p>}
        {error && <p role="alert" className="onboard-error">{error}</p>}
        {busy && <p role="status">Loading applications…</p>}
        {page && (page.items.length === 0 ? <p>No applications match these filters.</p> : <div className="onboard-table">
          <table><thead><tr><th scope="col">School</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
          <tbody>{page.items.map(row => <tr key={row.id}><td>{row.organization_name}</td><td>{row.category}</td>
            <td><span className="onboard-badge">{row.state}</span></td><td><button className="onboard-secondary" disabled={busy} onClick={() => void open(row.id)} aria-label={`Open ${row.organization_name}`}>Open</button></td></tr>)}</tbody></table>
        </div>)}
        <div className="onboard-actions"><button className="onboard-secondary" disabled={busy} onClick={() => void load(filters)}>First page</button>
          {page?.next_cursor && <button disabled={busy} onClick={() => void load({ ...filters, cursor: page.next_cursor! })}>Next page</button>}</div>
      </section>
      {detail && <section className="onboard-card onboard-detail" aria-label="Application details">
        <h2>{detail.registration.organization_name}</h2>
        <dl><dt>Reference</dt><dd>{detail.registration.id}</dd><dt>Applicant</dt><dd>{detail.registration.contact_name}</dd><dt>Contact</dt><dd>{detail.registration.contact}</dd>
          <dt>Privacy notice version</dt><dd>{detail.registration.notice_version}</dd><dt>Status</dt><dd className="onboard-badge">{state}</dd></dl>
        <h3>Review history</h3>
        {detail.history.length ? <ol>{detail.history.map(entry => <li key={entry.id}><strong>{entry.from_state} → {entry.to_state}</strong>
          <p>{entry.note}</p><small>{entry.created_at} · {entry.actor_user_id}</small></li>)}</ol> : <p>No review decisions yet.</p>}
        {reviewable && <form key={detail.registration.id} onSubmit={confirm}><label htmlFor="decision">Review action</label>
          <select id="decision" name="state">{state === 'submitted' ? <option value="reviewing">Start review</option> : <><option value="approved">Approve</option><option value="rejected">Reject</option></>}</select>
          <label htmlFor="note">Review note</label><textarea id="note" name="note" maxLength={1000} required rows={3} />
          <div className="onboard-actions"><button disabled={busy}>Review decision</button></div></form>}
      </section>}
      <dialog ref={dialog} aria-labelledby="decision-heading" onCancel={event => { if (busy) event.preventDefault(); }}>
        <h2 id="decision-heading">Confirm decision</h2>
        <p>Change {detail?.registration.organization_name} to <strong>{command?.state}</strong>?</p>
        <p>This records an application decision. Provisioning and access activation are separate.</p>
        {error && <p role="alert" className="onboard-error">{error}</p>}
        <div className="onboard-actions"><button className="onboard-secondary" disabled={busy} onClick={() => dialog.current?.close()}>Cancel</button>
          <button disabled={busy} onClick={() => void commit()}>{busy ? 'Saving…' : 'Confirm decision'}</button></div>
      </dialog>
    </div>
  </main>;
}
