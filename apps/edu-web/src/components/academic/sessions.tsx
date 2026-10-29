'use client';
import {useRef, type FormEvent} from 'react';
import {useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, Submit} from './controls';
export default function Sessions() {
  const data = useAcademicList('sessions'); const form = useRef<HTMLFormElement>(null);
  const command = useAcademicCommand('sessions', () => {form.current?.reset(); data.refresh();});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); const f = new FormData(e.currentTarget); void command.submit({name: String(f.get('name')).trim(), starts_on: String(f.get('starts_on')), ends_on: String(f.get('ends_on'))});}
  return <><PageHeading title="Academic sessions" section="Sessions" description="Create future calendars while preserving every historical academic reference."/>
    <div className="ac-split"><Panel title="Session calendar" description="Calendar dates belong to the school, not your browser timezone."><RecordList data={data} label="Sessions" columns={['Session', 'Date range', 'State']} searchable={r => r.name} empty="Create a future session to begin organizing your classes." row={r => [<strong key="name">{r.name}</strong>, <span key="dates">{r.starts_on} <span className="ac-muted">to</span> {r.ends_on}</span>, <span key="state" className="ac-pill">{r.state}</span>]}/></Panel>
    <Panel title="Create future session" description="Only future creation is available in this milestone."><form ref={form} onSubmit={submit} aria-busy={command.busy}><fieldset disabled={command.busy}><Field label="Session name" name="name" errors={command.fields}><input name="name" required maxLength={60} placeholder="e.g. 2030–2031"/></Field><div className="ac-form-row"><Field label="Start date" name="starts_on" errors={command.fields}><input name="starts_on" type="date" required/></Field><Field label="End date" name="ends_on" errors={command.fields}><input name="ends_on" type="date" required/></Field></div><p className="ac-note">The server checks that the start is after today in your configured school timezone, with a valid date range and no future/active overlap. No browser timezone is assumed.</p><Feedback error={command.error} message={command.message} refresh={data.refresh}/><Submit busy={command.busy}>Create session</Submit></fieldset></form></Panel></div></>;
}
