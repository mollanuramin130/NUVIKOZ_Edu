import {browserRequest} from './browser-auth';

export type StaffCandidate = {membership_id: string; display_name: string; role: 'principal' | 'teacher'};
export type StaffProfile = {id: string; membership_id: string; display_name: string; role: string;
  eligible_for_registration: boolean; created_at: string; updated_at: string};
type Page<T> = {items: T[]; next_cursor: string | null};

export function staffProfiles(q: string, cursor: string | undefined, signal: AbortSignal) {
  return browserRequest<Page<StaffProfile>>('staff', undefined, {query: {q: q || undefined, cursor}, signal});
}

export function staffCandidates(q: string, cursor: string | undefined, signal: AbortSignal) {
  return browserRequest<Page<StaffCandidate>>('staff/eligible-memberships', undefined, {query: {q: q || undefined, cursor}, signal});
}

export function staffDetail(id: string, signal: AbortSignal) {
  return browserRequest<StaffProfile>(`staff/${id}`, undefined, {signal});
}

export function registerStaff(id: string, key: string, signal: AbortSignal) {
  return browserRequest<{resource: StaffProfile; replayed: boolean}>('staff', {membership_id: id}, {idempotencyKey: key, signal});
}
