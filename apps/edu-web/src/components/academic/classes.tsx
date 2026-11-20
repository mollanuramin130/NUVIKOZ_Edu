'use client';
import {useRef, useState, type FormEvent} from 'react';
import {useAcademicAccess} from './workspace';
import type {AcademicSession} from '../../lib/academic-api';
import {useAcademicCommand, useAcademicList} from './data';
import {Feedback, Field, PageHeading, Panel, RecordList, Reference, SelectRecord, Submit} from './controls';
function OfferingForm({session, lookupKey, refresh}: {session: AcademicSession; lookupKey: number; refresh: () => void}) {
  const [classId, setClass] = useState('');
  const command = useAcademicCommand('class-offerings', () => refresh());
  return <form onSubmit={e => {e.preventDefault(); if (classId) void command.submit({session_id: session.id, class_id: classId});}} aria-busy={command.busy}><fieldset disabled={command.busy || session.state !== 'future'}><h3>Offer an existing class</h3><SelectRecord key={lookupKey} kind="classes" label="School class" value={classId} onChange={id => setClass(id)} describe={r => r.name}/><p className="ac-note">Only eligible future sessions accept new offerings. The server rechecks school-local dates.</p><Feedback error={command.error} message={command.message} refresh={refresh}/><Submit busy={command.busy}>Create class offering</Submit></fieldset></form>;
}
export default function Classes() {
  const {session, selectSession} = useAcademicAccess(); const [lookupKey, setLookupKey] = useState(0);
  const classes = useAcademicList('classes'); const offerings = useAcademicList('class-offerings', {session_id: session?.id}, !!session);
  const form = useRef<HTMLFormElement>(null);
  const createClass = useAcademicCommand('classes', () => {form.current?.reset(); classes.refresh(); setLookupKey(x => x+1);});
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault(); const d = new FormData(e.currentTarget); const label = String(d.get('grade_label') ?? '').trim(); void createClass.submit({name: String(d.get('name')).trim(), ...(label ? {grade_label: label} : {}), sort_order: Number(d.get('sort_order'))});}
  return <><PageHeading title="Classes & offerings" section="Classes" description="Maintain a reusable school catalog, then deliberately offer classes in a selected academic session."/>
    <div className="ac-split"><Panel title="School class catalog" description="A class definition is separate from a session offering."><RecordList data={classes} label="Classes" columns={['Class', 'Grade label', 'Sort order']} searchable={r => `${r.name} ${r.grade_label ?? ''}`} empty="Create your school's first class definition." row={r => [<strong key="name">{r.name}</strong>, r.grade_label ?? 'Not specified', r.sort_order]}/></Panel>
    <Panel title="Create class"><form ref={form} onSubmit={submit} aria-busy={createClass.busy}><fieldset disabled={createClass.busy}><Field label="Class name" name="name" errors={createClass.fields}><input name="name" required maxLength={80} placeholder="e.g. Senior secondary"/></Field><Field label="Grade label (optional)" name="grade_label" errors={createClass.fields}><input name="grade_label" maxLength={32}/></Field><Field label="Sort order" name="sort_order" errors={createClass.fields}><input name="sort_order" type="number" min={0} max={2147483647} step={1} defaultValue={0} required/></Field><Feedback error={createClass.error} message={createClass.message} refresh={classes.refresh}/><Submit busy={createClass.busy}>Create class</Submit></fieldset></form></Panel></div>
    <Panel title="Session class offerings" description="Classes are never automatically offered. Choose a session and class explicitly."><div className="ac-context"><SelectRecord kind="sessions" label="Academic session" value={session?.id ?? ''} onChange={(_, r) => {selectSession(r ?? null);}} describe={r => `${r.name} · ${r.state}`}/></div>
    {session ? <div className="ac-split ac-inner"><div><p className="ac-context-title">Offerings for <strong>{session.name}</strong></p><RecordList data={offerings} label="Class offerings" columns={['Class', 'Session']} empty="No classes are offered in this session yet." row={r => [classes.items.find(c => c.id === r.class_id)?.name ?? <Reference key="class" value={r.class_id}/>, session.name]}/></div><OfferingForm key={session.id} session={session} lookupKey={lookupKey} refresh={offerings.refresh}/></div> : <p className="ac-note">Select an academic session to view or create class offerings.</p>}</Panel></>;
}
