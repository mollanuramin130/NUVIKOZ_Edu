'use client';
import {useRef, useState, type FormEvent} from 'react';
import {useAcademicAccess} from './workspace';
import {useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, SelectRecord} from './controls';
import {Submit} from './controls';
function SectionForm({offering, refresh, future}: {offering: string; refresh: () => void; future: boolean}) {
  const ref = useRef<HTMLFormElement>(null);
  const command = useAcademicCommand('sections', () => {ref.current?.reset(); refresh();});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); void command.submit({class_offering_id: offering, name: String(new FormData(e.currentTarget).get('name')).trim()});}
  return <form ref={ref} onSubmit={submit} aria-busy={command.busy}><fieldset disabled={command.busy || !future}><Field label="Section name" name="name" errors={command.fields}><input name="name" required maxLength={40} placeholder="e.g. Section A"/></Field><p className="ac-note">Class teacher assignment is unavailable until the Staff Assignment milestone. No teacher ID will be submitted.</p><Feedback error={command.error} message={command.message} refresh={refresh}/><Submit busy={command.busy}>Create section</Submit></fieldset></form>;
}
export default function Sections() {
  const {session, selectSession} = useAcademicAccess(); const [offering, setOffering] = useState('');
  const classes = useAcademicList('classes', {}, true, true);
  const sections = useAcademicList('sections', {class_offering_id: offering || undefined}, !!offering);
  return <><PageHeading title="Class sections" section="Sections" description="Organize sections within a specific class offering, without mixing sessions or creating student records."/>
    <Panel title="Academic context"><div className="ac-form-row"><SelectRecord kind="sessions" label="Academic session" value={session?.id ?? ''} onChange={(_, r) => {selectSession(r ?? null); setOffering('');}} describe={r => `${r.name} · ${r.state}`}/><SelectRecord key={session?.id ?? 'none'} kind="class-offerings" label="Class offering" value={offering} onChange={id => setOffering(id)} disabled={!session} filters={{session_id: session?.id}} describe={r => classes.items.find(c => c.id === r.class_id)?.name ?? `Class reference ${r.class_id.slice(-8)}`}/></div>{classes.next && <button className="ac-text-button" onClick={classes.forward} disabled={classes.busy}>Load more class names</button>}<Feedback error={classes.error} refresh={classes.refresh}/>{session && <p className="ac-note">Selected session: <strong>{session.name}</strong> · {session.starts_on} to {session.ends_on}</p>}</Panel>
    {offering ? <div className="ac-split"><Panel title="Sections in this offering"><RecordList key={offering} data={sections} label="Sections" columns={['Section', 'Class teacher']} searchable={r => r.name} empty="Create a section for this selected class and session." row={r => [<strong key="name">{r.name}</strong>, <span className="ac-muted" key="teacher">Assignment unavailable</span>]}/></Panel><Panel title="Create section"><SectionForm key={offering} offering={offering} refresh={sections.refresh} future={session?.state === 'future'}/></Panel></div> : <div className="ac-guidance"><span aria-hidden="true">▥</span><h2>Choose your academic context</h2><p>Select a session and one of its class offerings to manage sections.</p></div>}</>;
}
