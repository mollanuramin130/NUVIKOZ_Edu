import { browserRequest } from './browser-auth';
export type AcademicSession = {id: string; name: string; starts_on: string; ends_on: string; state: 'future' | 'active' | 'closed' | 'archived'; version: number};
export type SchoolClass = {id: string; name: string; grade_label: string | null; sort_order: number; version: number};
export type ClassOffering = {id: string; session_id: string; class_id: string; version: number};
export type Section = {id: string; class_offering_id: string; name: string; class_teacher_membership_id: null; version: number};
export type Subject = {id: string; code: string; name: string; version: number};
export type SubjectOffering = {id: string; class_offering_id: string; subject_id: string; compulsory: boolean; version: number};
export type Resources = {sessions: AcademicSession; classes: SchoolClass; 'class-offerings': ClassOffering; sections: Section; subjects: Subject; 'subject-offerings': SubjectOffering};
export type Kind = keyof Resources;
export type Inputs = {
  sessions: {name: string; starts_on: string; ends_on: string};
  classes: {name: string; grade_label?: string; sort_order: number};
  'class-offerings': {session_id: string; class_id: string};
  sections: {class_offering_id: string; name: string};
  subjects: {code: string; name: string};
  'subject-offerings': {class_offering_id: string; subject_id: string; compulsory: boolean};
};
export type Filters = {sessions: object; classes: object; subjects: object; 'class-offerings': {session_id?: string; class_id?: string}; sections: {class_offering_id?: string}; 'subject-offerings': {class_offering_id?: string; subject_id?: string}};
export type AcademicPage<K extends Kind> = {items: Resources[K][]; next_cursor: string | null};
export function listAcademic<K extends Kind>(kind: K, filters: Filters[K] & {cursor?: string} = {}, signal?: AbortSignal) {
  return browserRequest<AcademicPage<K>>(`academic/${kind}`, undefined, {query: filters as Record<string, string | undefined>, signal});
}
export function createAcademic<K extends Kind>(kind: K, body: Inputs[K], key: string, signal?: AbortSignal) {
  return browserRequest<{resource: Resources[K]; replayed: boolean}>(`academic/${kind}`, body, {idempotencyKey: key, signal});
}
export function newAcademicIntent() { return crypto.randomUUID(); }
