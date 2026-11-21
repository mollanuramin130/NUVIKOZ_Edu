'use client';
import {cloneElement, useId, useState, type ReactElement, type ReactNode} from 'react';
import type {Filters, Kind, Resources} from '../../lib/academic-api';
import {useAcademicList} from './data';
export function PageHeading({title, description, section}: {title: string; description: string; section: string}) {
  return <div className="ac-page-heading"><div><p className="ac-eyebrow">ACADEMIC SETUP / {section.toUpperCase()}</p><h1>{title}</h1><p>{description}</p></div><span className="ac-secure-badge">School Admin · MFA protected</span></div>;
}
export function Panel({title, description, children}: {title: string; description?: string; children: ReactNode}) {
  return <section className="ac-panel" aria-label={title}><div className="ac-panel-heading"><h2>{title}</h2>{description && <p>{description}</p>}</div>{children}</section>;
}
export function Field({label, name, errors = {}, children, help}: {label: string; name: string; errors?: Record<string,string[]>; children: ReactElement<{id?: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string}>; help?: string}) {
  const id = useId(); const described = [help ? `${id}-help` : '', errors[name] ? `${id}-error` : ''].filter(Boolean).join(' ');
  return <div className="ac-field"><label htmlFor={id}>{label}</label>{cloneElement(children, {id, 'aria-invalid': !!errors[name], 'aria-describedby': described || undefined})}{help && <p id={`${id}-help`} className="ac-help">{help}</p>}{errors[name] && <p id={`${id}-error`} className="ac-field-error" role="alert">{errors[name].join(' ')}</p>}</div>;
}
export function Feedback({error, message, refresh}: {error: string; message?: string; refresh?: () => void}) {
  return <>{error && <div className="ac-feedback ac-error" role="alert">{error}{refresh && <button type="button" className="ac-text-button" onClick={refresh}>Refresh records</button>}</div>}{message && <p className="ac-feedback ac-success" role="status">{message}</p>}</>;
}
export function SelectRecord<K extends Kind>({kind, label, value, onChange, describe, filters = {}, disabled = false, eligible, help}: {kind: K; label: string; value: string; onChange: (id: string, record?: Resources[K]) => void; describe: (record: Resources[K]) => string; filters?: Filters[K]; disabled?: boolean; eligible?: (record: Resources[K]) => boolean; help?: string}) {
  const data = useAcademicList(kind, filters, !disabled, true); const id = useId();
  return <div className="ac-field"><label htmlFor={id}>{label}</label><select id={id} value={value} disabled={disabled || data.busy} onChange={e => onChange(e.target.value, data.items.find(x => x.id === e.target.value))} required><option value="">{disabled ? 'Select the parent context first' : data.busy ? 'Loading available records…' : `Choose ${label.toLowerCase()}`}</option>{data.items.map(record => <option key={record.id} value={record.id} disabled={eligible ? !eligible(record) : false}>{describe(record)}</option>)}</select>{help && <p className="ac-help">{help}</p>}{data.next && <button type="button" className="ac-text-button" disabled={data.busy} onClick={data.forward}>Load more {label.toLowerCase()} options</button>}<Feedback error={data.error} refresh={data.refresh}/></div>;
}
export type ListState<T> = {items: T[]; next: string | null; busy: boolean; error: string; page: number; refresh: () => void; forward: () => void; back: () => void};
export function RecordList<T extends {id: string}>({data, columns, row, label, empty, searchable}: {data: ListState<T>; columns: string[]; row: (item: T) => ReactNode[]; label: string; empty: string; searchable?: (item:T) => string}) {
  const [search, setSearch] = useState('');
  const records = searchable ? data.items.filter(item => searchable(item).toLocaleLowerCase().includes(search.toLocaleLowerCase())) : data.items;
  return <div className="ac-list"><div className="ac-toolbar">{searchable ? <label className="ac-search">Search this loaded page<input type="search" value={search} onChange={e => setSearch(e.target.value)} placeholder={`Search ${label.toLowerCase()}`}/></label> : <span>Up to 25 records per page</span>}<button type="button" className="ac-secondary" disabled={data.busy} onClick={data.refresh}>Refresh</button></div>
    <Feedback error={data.error} refresh={data.refresh}/>
    {data.busy ? <div role="status" className="ac-loading"><span className="ac-skeleton"/>Loading {label.toLowerCase()}…</div> : records.length ? <div className="ac-table-wrap" tabIndex={0} role="region" aria-label={`${label} table — scroll horizontally when needed`}><table><caption className="ac-sr-only">{label}, current page only</caption><thead><tr>{columns.map(c => <th key={c} scope="col">{c}</th>)}</tr></thead><tbody>{records.map(item => <tr key={item.id}>{row(item).map((cell, i) => <td key={i} data-label={columns[i]}>{cell}</td>)}</tr>)}</tbody></table></div> : !data.error && <div className="ac-empty"><span aria-hidden="true">◇</span><h3>{search ? 'No matches on this page' : `No ${label.toLowerCase()} loaded`}</h3><p>{search ? 'Clear search or review another page. Search does not include unloaded records.' : empty}</p></div>}
    <div className="ac-pagination"><span>{data.busy ? 'Loading…' : `${data.items.length} loaded records · page ${data.page}`}</span><div><button type="button" className="ac-secondary" disabled={data.busy || data.page < 2} onClick={() => {setSearch(''); data.back();}}>Previous page</button><button type="button" className="ac-secondary" disabled={data.busy || !data.next} onClick={() => {setSearch(''); data.forward();}}>Next page</button></div></div>
  </div>;
}
export function Submit({busy, children}: {busy: boolean; children: ReactNode}) {return <button className="ac-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : children}</button>;}

export function Reference({value}: {value: string}) { return <code className="ac-reference" title={value}>Reference · {value.slice(-8)}</code>; }
