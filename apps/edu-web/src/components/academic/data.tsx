'use client';
import {useCallback, useEffect, useRef, useState} from 'react';
import {createAcademic, listAcademic, newAcademicIntent, type Filters, type Inputs, type Kind, type Resources} from '../../lib/academic-api';
import {AuthError} from '../../lib/browser-auth';
import {useAcademicAccess} from './workspace';
export function academicError(e: unknown): string {
  if (e instanceof AuthError) {
    if (e.status === 409) return 'The academic data may have changed, or this record already exists. Refresh and review the current records before submitting a new intent.';
    if (e.status === 422) return 'Check the highlighted fields and the academic relationship requirements.';
    if (e.status === 429) return 'Too many requests. Wait a moment before retrying.';
    if (e.status === 419) return 'Your session changed. The command was not replayed. Review and submit again.';
    if (e.status === 403) return 'The school is read-only or this operation is unavailable under current eligibility or configuration.';
    if (e.status >= 500) return 'The service could not confirm this operation. Review current records before intentionally retrying the unchanged request.';
  }
  return 'The service could not confirm this request. Check your connection, refresh the records, and retry only when ready.';
}
export function useAcademicList<K extends Kind>(kind: K, filters: Filters[K] = {}, enabled = true, append = false) {
  const [items, setItems] = useState<Resources[K][]>([]); const [next, setNext] = useState<string | null>(null);
  const [cursors, setCursors] = useState<(string | undefined)[]>([undefined]);
  const [loadedKey, setLoadedKey] = useState('');
  const [busy, setBusy] = useState(enabled); const [error, setError] = useState('');
  const sequence = useRef(0); const abort = useRef<AbortController | null>(null); const {failure} = useAcademicAccess();
  const filterKey = JSON.stringify(filters);
  const load = useCallback(async (history: (string | undefined)[], more = false) => {
    const id = ++sequence.current; abort.current?.abort(); const controller = new AbortController(); abort.current = controller;
    await Promise.resolve();
    if (controller.signal.aborted) return;
    setBusy(true); setError(''); if (!more) setItems([]);
    try {
      const result = await listAcademic(kind, {...JSON.parse(filterKey), cursor: history[history.length - 1]}, controller.signal);
      if (sequence.current !== id || controller.signal.aborted) return;
      setItems(old => more ? [...old, ...result.items.filter(x => !old.some(p => p.id === x.id))] : result.items); setNext(result.next_cursor); setCursors(history); setLoadedKey(kind + filterKey);
    } catch (e) { if (sequence.current === id && !controller.signal.aborted) {setError(academicError(e)); failure(e);} }
    finally {if (sequence.current === id && !controller.signal.aborted) setBusy(false);}
  }, [kind, filterKey, failure]);
  const cancel = useCallback(() => {sequence.current++; abort.current?.abort();}, []);
  useEffect(() => {
    let mounted = true;
    void Promise.resolve().then(() => {if (mounted && enabled) void load([undefined]);});
    return () => {mounted = false; cancel();};
  }, [enabled, load, cancel]);
  return {items: enabled && loadedKey === kind + filterKey ? items : [], next: enabled && loadedKey === kind + filterKey ? next : null, busy: enabled && busy, error: enabled ? error : '', page: cursors.length,
    refresh: () => void load([undefined]),
    forward: () => {if (next && !busy) void load([...cursors, next], append);},
    back: () => {if (cursors.length > 1 && !busy) void load(cursors.slice(0, -1));},
  };
}
export function useAcademicCommand<K extends Kind>(kind: K, success: (record: Resources[K]) => void) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const lock = useRef(false); const intent = useRef<{body: string; key: string} | null>(null); const active = useRef(true); const abort = useRef<AbortController | null>(null);
  const {failure} = useAcademicAccess();
  useEffect(() => {active.current = true; return () => {active.current = false; abort.current?.abort(); intent.current = null;};}, []);
  async function submit(body: Inputs[K]) {
    if (lock.current) return;
    const json = JSON.stringify(body);
    if (!intent.current || intent.current.body !== json) intent.current = {body: json, key: newAcademicIntent()};
    lock.current = true; setBusy(true); setError(''); setMessage(''); setFields({});
    const controller = new AbortController(); abort.current = controller;
    try {
      const result = await createAcademic(kind, body, intent.current.key, controller.signal);
      if (!active.current || controller.signal.aborted) return false;
      intent.current = null; setMessage(result.replayed ? 'The existing committed record was confirmed. No duplicate was created.' : 'Record created successfully.'); success(result.resource);
      return true;
    } catch (e) {
      if (active.current && !controller.signal.aborted) {setError(academicError(e)); if (e instanceof AuthError) setFields(e.fields); failure(e);}
      return false;
    } finally {lock.current = false; if (active.current) setBusy(false);}
  }
  return {busy, error, message, fields, submit};
}
