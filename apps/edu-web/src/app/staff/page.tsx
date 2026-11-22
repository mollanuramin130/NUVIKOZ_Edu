'use client';

import {useEffect, useRef, useState, type FormEvent} from 'react';
import AcademicWorkspace, {useAcademicAccess} from '../../components/academic/workspace';
import {PageHeading, Panel} from '../../components/academic/controls';
import {AuthError} from '../../lib/browser-auth';
import {registerStaff, staffCandidates, staffDetail, staffProfiles, type StaffCandidate, type StaffProfile} from '../../lib/staff-api';
import '../academic/academic.css';

const roleLabel = (role: string) => ({principal: 'Principal', teacher: 'Teacher', school_admin: 'School Admin', parent: 'Parent', student: 'Student'}[role] ?? role);

function StaffDirectory() {
  const {failure} = useAcademicAccess();
  const [profiles, setProfiles] = useState<StaffProfile[]>([]);
  const [candidates, setCandidates] = useState<StaffCandidate[]>([]);
  const [listSearch, setListSearch] = useState('');
  const [candidateSearch, setCandidateSearch] = useState('');
  const [listNext, setListNext] = useState<string | null>(null);
  const [candidateNext, setCandidateNext] = useState<string | null>(null);
  const [detail, setDetail] = useState<StaffProfile | null>(null);
  const [selected, setSelected] = useState<StaffCandidate | null>(null);
  const [busy, setBusy] = useState(false);
  const [readOnly, setReadOnly] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const request = useRef<AbortController | null>(null);
  const intent = useRef<{id: string; key: string} | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  async function perform(work: (signal: AbortSignal) => Promise<void>, registration = false) {
    if (request.current) return;
    const controller = new AbortController();
    request.current = controller;
    setBusy(true); setError('');
    try {
      await work(controller.signal);
    } catch (e) {
      if (controller.signal.aborted) return;
      // The API intentionally does not publish entitlement or stage metadata.
      if (registration && e instanceof AuthError && e.status === 403 && e.code === 'HTTP_403') {
        setReadOnly(true);
        dialog.current?.close();
        setSelected(null);
        intent.current = null;
        setNotice('Staff registration is unavailable. Authorized staff reads remain available.');
      } else {
        failure(e);
        setError(e instanceof AuthError ? e.message : 'Could not complete the request. Try again.');
      }
    } finally {
      if (request.current === controller) {request.current = null; setBusy(false);}
    }
  }

  async function loadProfiles(signal: AbortSignal, q: string, cursor?: string) {
    const result = await staffProfiles(q, cursor, signal);
    if (!signal.aborted) {setProfiles(result.items); setListNext(result.next_cursor); setListSearch(q);}
  }

  async function loadCandidates(signal: AbortSignal, q: string, cursor?: string) {
    const result = await staffCandidates(q, cursor, signal);
    if (!signal.aborted) {setCandidates(result.items); setCandidateNext(result.next_cursor); setCandidateSearch(q);}
  }

  useEffect(() => {
    let mounted = true;
    void Promise.resolve().then(() => {
      if (mounted) void perform(async signal => {
        await loadProfiles(signal, '');
        if (!signal.aborted) await loadCandidates(signal, '');
      });
    });
    return () => {mounted = false; request.current?.abort(); request.current = null; intent.current = null;};
    // The protected workspace remounts the complete directory across session contexts.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function search(event: FormEvent<HTMLFormElement>, lookup: boolean) {
    event.preventDefault();
    const query = String(new FormData(event.currentTarget).get('q')).trim();
    if (query.length === 1) {setError('Enter at least two search characters.'); return;}
    void perform(signal => lookup ? loadCandidates(signal, query) : loadProfiles(signal, query));
  }

  function confirm(candidate: StaffCandidate) {
    setSelected(candidate);
    if (intent.current?.id !== candidate.membership_id) {
      intent.current = {id: candidate.membership_id, key: crypto.randomUUID()};
    }
    dialog.current?.showModal();
  }

  function register() {
    const pending = intent.current;
    if (!pending || readOnly) return;
    void perform(async signal => {
      const result = await registerStaff(pending.id, pending.key, signal);
      if (signal.aborted) return;
      dialog.current?.close(); setSelected(null); intent.current = null;
      setDetail(result.resource); setNotice('Staff profile registered.');
      setCandidates(items => items.filter(item => item.membership_id !== pending.id));
      await loadProfiles(signal, listSearch);
      if (!signal.aborted) await loadCandidates(signal, candidateSearch);
    }, true);
  }

  return <>
    <PageHeading title="Staff profiles" section="Staff" description="Find and register profiles for existing school staff memberships."/>
    {error && <p role="alert" className="ac-banner">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    {busy && <p role="status">Working…</p>}
    <Panel title="Staff directory">
      <form onSubmit={event => search(event, false)}><label>Search profiles<input name="q" maxLength={80}/></label>
        <button disabled={busy}>Search</button></form>
      {profiles.length ? <ul>{profiles.map(profile => <li key={profile.id}>
        <button type="button" disabled={busy} onClick={() => void perform(async signal => {
          const result = await staffDetail(profile.id, signal);
          if (!signal.aborted) setDetail(result);
        })}>{profile.display_name} · {roleLabel(profile.role)}</button>
        {!profile.eligible_for_registration && <span> · Membership not currently eligible</span>}
      </li>)}</ul> : !busy && <p>No staff profiles match.</p>}
      {listNext && <button type="button" disabled={busy} onClick={() => void perform(signal => loadProfiles(signal, listSearch, listNext))}>Next profiles</button>}
    </Panel>
    {detail && <Panel title="Staff detail"><p>{detail.display_name} · {roleLabel(detail.role)}</p>
      <p>{detail.eligible_for_registration ? 'Eligible membership' : 'Membership not currently eligible'}</p></Panel>}
    <Panel title="Eligible memberships">
      {readOnly && <p>Read-only access</p>}
      <form onSubmit={event => search(event, true)}><label>Search eligible memberships<input name="q" maxLength={80}/></label>
        <button disabled={busy}>Search</button></form>
      {candidates.length ? <ul>{candidates.map(candidate => <li key={candidate.membership_id}>
        {candidate.display_name} · {roleLabel(candidate.role)} <button type="button" disabled={busy || readOnly}
          onClick={() => confirm(candidate)}>Register {candidate.display_name}</button>
      </li>)}</ul> : !busy && <p>No eligible memberships match.</p>}
      {candidateNext && <button type="button" disabled={busy} onClick={() => void perform(signal => loadCandidates(signal, candidateSearch, candidateNext))}>Next memberships</button>}
    </Panel>
    <dialog ref={dialog} aria-label="Confirm staff registration" onCancel={event => {if (busy) event.preventDefault();}}>
      {selected && <><h2>Register {selected.display_name}?</h2><p>This links a profile to the existing {roleLabel(selected.role)} membership.</p>
        {error && <p role="alert">{error}</p>}
        <button type="button" disabled={busy} onClick={() => dialog.current?.close()}>Cancel</button>
        <button type="button" disabled={busy || readOnly} onClick={register}>Confirm registration</button></>}
    </dialog>
  </>;
}

export default function StaffPage() {
  return <AcademicWorkspace><StaffDirectory/></AcademicWorkspace>;
}
