'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { AuthError, browserRequest, onSessionChange } from '../../../lib/browser-auth';
import '../../onboarding.css';

type State = 'submitted' | 'reviewing' | 'approved' | 'rejected';
type Decision = Exclude<State, 'submitted'>;
type Row = { id: string; organization_name: string; category: string; state: State; submitted_at: string };
type Page = { items: Row[]; next_cursor: string | null };
type ActivationAction = 'issue' | 'reissue' | 'revoke';
const activationLabels: Record<string, string> = {
  awaiting_verified_delivery: 'Awaiting verified delivery', pending_delivery: 'Pending delivery',
  delivered: 'Delivered · awaiting password setup', expired: 'Expired', revoked: 'Revoked',
  superseded: 'Superseded', consumed: 'Account activated',
};
type ConversionPreview = { record_counts: Record<string, number>; conflicts: { table: string; id: string; field: string; code: string }[]; eligible: boolean; demo_state: string; access_after: string; policy_after: string; preview: string | null };
type EntitlementAction = 'grant_demo' | 'grant_free_live' | 'enable' | 'suspend';
const entitlementActions: Record<EntitlementAction, string> = {
  grant_demo: 'Grant 30-day Demo', grant_free_live: 'Grant Free Live', enable: 'Enable school access', suspend: 'Suspend school access',
};
type Detail = {
  entitlement: { policy: string | null; access: string; state: string; starts_at: string | null; ends_at: string | null;
    history: { sequence: number; action: EntitlementAction | 'convert_demo'; starts_at: string; actor_id: string }[];
    quota: { bytes: number; active: number; daily: number; school_date: string; warning: boolean; storage_blocked: boolean;
      reserved_bytes: number; reserved_active: number; reserved_daily: number; max_pdf_bytes: number; max_active_pdfs: number; max_daily_pdfs: number; max_total_bytes: number } | null } | null;
  activation: { state: string; delivery_configured: boolean; history: { sequence: number; state: string; operation: string; expires_at: string | null; created_at: string; actor_id: string }[] };
  provisioning: { school_id: string; code: string; created_at: string; actor_user_id: string; activation_state: string } | null;
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
  const [conversion, setConversion] = useState<ConversionPreview>();
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState('');
  const [command, setCommand] = useState<{ state: Decision; note: string; command_id: string } | { state: 'provision' | ActivationAction; command_id: string } | { state: 'conversion'; preview: string; term_days: number; command_id: string } | { state: 'entitlement'; action: EntitlementAction; term_days?: number; command_id: string }>();
  const pending = useRef<AbortController | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  const load = useCallback(async (query: { search?: string; state?: string; cursor?: string }) => {
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true);
    setError('');
    setDetail(undefined);
    setConversion(undefined);
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
    setBusy(true); setError(''); setFeedback(''); setDetail(undefined); setConversion(undefined);
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

  async function previewConversion() {
    if (!detail || busy) return;
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true); setError(''); setConversion(undefined);
    try {
      const result = await browserRequest<ConversionPreview>(`platform/registrations/${detail.registration.id}/conversion`, undefined, { signal: request.signal });
      if (!request.signal.aborted) setConversion(result);
    } catch (failure) {
      if (!request.signal.aborted) setError(message(failure));
    } finally { if (!request.signal.aborted) setBusy(false); }
  }

  async function commit() {
    if (!detail || !command || busy) return;
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setBusy(true); setError('');
    try {
      const reviewing = 'note' in command;
      const provisioning = command.state === 'provision';
      const entitlement = command.state === 'entitlement';
      const converting = command.state === 'conversion';
      const endpoint = reviewing ? 'review' : provisioning ? 'provision' : entitlement ? 'entitlement' : converting ? 'conversion' : `activation/${command.state}`;
      await browserRequest(`platform/registrations/${detail.registration.id}/${endpoint}`, reviewing ? command : converting ? { preview: command.preview, term_days: command.term_days, confirmed: true, command_id: command.command_id } : entitlement ? { action: command.action, term_days: command.term_days, command_id: command.command_id } : { command_id: command.command_id }, { signal: request.signal });
      if (request.signal.aborted) return;
      dialog.current?.close();
      setCommand(undefined);
      setFeedback(provisioning ? 'School provisioned. Access is pending activation.' : reviewing ? 'Review decision recorded.' : converting ? 'Converted to Free Live. School access is suspended.' : entitlement ? 'Entitlement action recorded.' : 'Activation action recorded.');
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
  const entitlement = detail?.entitlement;
  const quota = entitlement?.quota;
  const activation = detail?.activation;
  const actions: ActivationAction[] = !activation || activation.state === 'consumed' ? []
    : activation.state === 'awaiting_verified_delivery' ? ['issue']
    : ['pending_delivery', 'delivered'].includes(activation.state) ? ['reissue', 'revoke'] : ['reissue'];
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
        {state === 'approved' && <section aria-label="School provisioning">
          <h3>School provisioning</h3>
          {detail.provisioning ? <dl><dt>School code</dt><dd>{detail.provisioning.code}</dd>
            <dt>Activation</dt><dd>{activationLabels[detail.activation.state] ?? detail.activation.state}</dd>
            <dt>Provisioned at</dt><dd>{detail.provisioning.created_at}</dd>
            <dt>Provisioned by</dt><dd>{detail.provisioning.actor_user_id}</dd></dl>
            : <><p>No school record has been provisioned.</p><button disabled={busy} onClick={() => {
              setError(''); setCommand({ state: 'provision', command_id: crypto.randomUUID() }); dialog.current?.showModal();
            }}>Provision school</button></>}
        </section>}
        {detail.provisioning && activation && <section aria-label="Activation history">
          <h3>Activation history</h3>
          {!activation.delivery_configured && <p>Trusted delivery is unavailable.</p>}
          {activation.history.length ? <ol>{activation.history.map(item => <li key={item.sequence}>
            <strong>{item.operation} · {activationLabels[item.state] ?? item.state}</strong>
            <p>{item.created_at} · {item.actor_id}{item.expires_at && <> · Expires {item.expires_at}</>}</p>
          </li>)}</ol> : <p>No activation actions yet.</p>}
          <div className="onboard-actions">{actions.map(action => <button key={action}
            disabled={busy || (action !== 'revoke' && !activation.delivery_configured)} onClick={() => {
              setError(''); setCommand({ state: action, command_id: crypto.randomUUID() }); dialog.current?.showModal();
            }}>{action === 'issue' ? 'Issue proof' : action === 'reissue' ? 'Reissue proof' : 'Revoke proof'}</button>)}</div>
        </section>}
        {entitlement && <section aria-label="School entitlement">
          <h3>School entitlement</h3>
          <dl><dt>Policy</dt><dd>{entitlement.policy ?? 'Unassigned'}</dd><dt>Term status</dt><dd>{entitlement.state}</dd>
            <dt>School access</dt><dd>{entitlement.access}</dd><dt>Starts</dt><dd>{entitlement.starts_at ?? '—'}</dd>
            <dt>Expires</dt><dd>{entitlement.ends_at ?? '—'}</dd></dl>
          {quota && <><dl><dt>Stored PDF bytes</dt><dd>{quota.bytes.toLocaleString()} / {quota.max_total_bytes.toLocaleString()}</dd>
            <dt>Active PDFs</dt><dd>{quota.active} / {quota.max_active_pdfs}</dd>
            <dt>New PDFs today ({quota.school_date})</dt><dd>{quota.daily} / {quota.max_daily_pdfs}</dd>
            <dt>Pending reservations</dt><dd>{quota.reserved_bytes.toLocaleString()} bytes · {quota.reserved_active} active slots · {quota.reserved_daily} today</dd>
            <dt>Per PDF</dt><dd>{quota.max_pdf_bytes.toLocaleString()} bytes</dd></dl>
            {quota.storage_blocked ? <p role="status">Storage limit reached.</p> : quota.warning && <p role="status">Storage usage is at least 80%.</p>}</>}
          {activation?.state === 'consumed' && <form onSubmit={event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const action = data.get('entitlement-action') as EntitlementAction;
            const days = Number(data.get('entitlement-days'));
            if (!action || (action === 'grant_free_live' && (!Number.isInteger(days) || days < 1 || days > 365))) {
              setError('Choose an available action and enter a valid Free Live term if required.');
              return;
            }
            setError(''); setCommand({ state: 'entitlement', action, term_days: action === 'grant_free_live' ? days : undefined, command_id: crypto.randomUUID() });
            dialog.current?.showModal();
          }}>
            <label htmlFor="entitlement-action">Entitlement action</label>
            <select id="entitlement-action" name="entitlement-action">
              {(!entitlement.policy ? ['grant_demo', 'grant_free_live']
                : [...(entitlement.state === 'expired' && entitlement.policy === 'free-live-v1' ? ['grant_free_live'] : []),
                  ...(entitlement.access === 'suspended' ? (entitlement.state === 'current' ? ['enable'] : []) : ['suspend'])])
                .map(action => <option key={action} value={action}>{entitlementActions[action as EntitlementAction]}</option>)}
            </select>
            <label htmlFor="entitlement-days">Term days (Free Live only)</label><input id="entitlement-days" name="entitlement-days" type="number" min={1} max={365} />
            <div className="onboard-actions"><button disabled={busy}>Review entitlement action</button></div>
          </form>}
          {entitlement.policy === 'demo-v1' && activation?.state === 'consumed' &&
            <button type="button" disabled={busy} onClick={() => void previewConversion()}>Preview Free Live conversion</button>}
          {conversion && Object.keys(conversion.record_counts).length > 0 && <dl>
            {Object.entries(conversion.record_counts).map(([table, count]) =>
              <div key={table}><dt>{table.replaceAll('_', ' ')}</dt><dd>{count}</dd></div>)}
          </dl>}
          {conversion && conversion.conflicts.length > 0 && <div role="alert">
            <h4>Resolve these record conflicts before conversion</h4>
            <ul>{conversion.conflicts.map(conflict => <li key={`${conflict.table}:${conflict.id}:${conflict.field}`}>
              {conflict.table.replaceAll('_', ' ')} · {conflict.id} · {conflict.code.replaceAll('_', ' ')}
            </li>)}</ul>
          </div>}
          {conversion && (conversion.eligible && conversion.preview ? <form onSubmit={event => {
            event.preventDefault();
            const days = Number(new FormData(event.currentTarget).get('conversion-days'));
            if (!conversion.preview || !Number.isInteger(days) || days < 1 || days > 365) return;
            setCommand({ state: 'conversion', preview: conversion.preview, term_days: days, command_id: crypto.randomUUID() });
            dialog.current?.showModal();
          }}>
            <p role="status">Demo is {conversion.demo_state}. School records stay in place. Shared samples stay in the library.
              Access after conversion is {conversion.access_after}.</p>
            <label htmlFor="conversion-days">Free Live term (days)</label>
            <input id="conversion-days" name="conversion-days" type="number" required min={1} max={365} />
            <button disabled={busy}>Review conversion</button>
          </form> : <p role="status">This school is not eligible for conversion.</p>)}
          {entitlement.history.length ? <ol>{entitlement.history.map(item => <li key={item.sequence}>
            {item.action === 'convert_demo' ? 'Converted Demo to Free Live' : entitlementActions[item.action]} · {item.starts_at} · {item.actor_id}</li>)}</ol> : <p>No entitlement actions yet.</p>}
        </section>}
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
        <p hidden={!command || !('note' in command)}>Change {detail?.registration.organization_name} to <strong>{command?.state}</strong>?</p>
        <p>{command?.state === 'provision' ? 'Create a suspended school and inactive School Admin. Activation and entitlements remain pending.' : command?.state === 'conversion' ? `Convert to Free Live for ${command.term_days} days. School access will be suspended until separately enabled.` : command?.state === 'entitlement' ? entitlementActions[command.action] : command && !('note' in command) ? `Confirm ${command.state} for the initial School Admin proof. School access remains suspended.` : 'This records an application decision. Provisioning and access activation are separate.'}</p>
        {error && <p role="alert" className="onboard-error">{error}</p>}
        <div className="onboard-actions"><button className="onboard-secondary" disabled={busy} onClick={() => dialog.current?.close()}>Cancel</button>
          <button disabled={busy} onClick={() => void commit()}>{busy ? 'Saving…' : 'Confirm decision'}</button></div>
      </dialog>
    </div>
  </main>;
}
