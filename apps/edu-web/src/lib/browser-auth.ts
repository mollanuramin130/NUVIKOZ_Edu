export type Identity = { user_id: string; school_id: string; role: string; mfa_required?: boolean };
export class AuthError extends Error {
  constructor(public status: number, public code: string, message: string, public fields: Record<string, string[]> = {}) { super(message); }
}
const base = process.env.NEXT_PUBLIC_EDU_API_BASE_URL;
let csrf: string | undefined;
export function onSessionChange(listener: () => void) {
  const channel = new BroadcastChannel('edu-session');
  channel.onmessage = () => { csrf = undefined; listener(); };
  return () => channel.close();
}
function announceSessionChange() {
  const channel = new BroadcastChannel('edu-session');
  channel.postMessage('changed'); channel.close();
}
export type BrowserOptions = { signal?: AbortSignal; query?: Record<string, string | undefined>; idempotencyKey?: string };
const academicFilters: Record<string, string[]> = {
  sessions: ['cursor'], classes: ['cursor'], subjects: ['cursor'],
  'class-offerings': ['cursor', 'session_id', 'class_id'], sections: ['cursor', 'class_offering_id'],
  'subject-offerings': ['cursor', 'class_offering_id', 'subject_id'],
};
export async function browserRequest<T>(path: string, body?: unknown, options: BrowserOptions = {}): Promise<T> {
  if (!base) throw new Error('The Edu API origin is not configured.');
  if (!/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(path)) throw new Error('Invalid Edu API path.');
  const target = new URL(base);
  if (target.username || target.password || target.search || target.hash) throw new Error('Invalid Edu API configuration.');
  const url = new URL(`/api/v1/browser/${path}`, target.origin);
  const kind = path.startsWith('academic/') ? path.slice(9) : '';
  if (options.query) {
    if (body !== undefined || !academicFilters[kind]) throw new Error('Query parameters are not available for this request.');
    for (const [key, value] of Object.entries(options.query)) {
      if (!academicFilters[kind].includes(key)) throw new Error('Unsupported academic filter.');
      if (value !== undefined) {
        if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(value)) throw new Error('Invalid academic reference.');
        url.searchParams.set(key, value);
      }
    }
  }
  if (options.idempotencyKey && (body === undefined || !academicFilters[kind] || !/^[A-Za-z0-9_-]{16,160}$/.test(options.idempotencyKey))) throw new Error('Invalid academic command key.');
  const loopback = (host: string) => ['localhost', '127.0.0.1'].includes(host);
  const localHttp = process.env.NODE_ENV === 'development' && process.env.NEXT_PUBLIC_EDU_ALLOW_LOCAL_HTTP === 'true'
    && loopback(target.hostname) && loopback(window.location.hostname);
  if (!localHttp && (target.protocol !== 'https:' || window.location.protocol !== 'https:')) {
    throw new Error('HTTPS is required to sign in securely.');
  }
  if (body !== undefined && !csrf) await bootstrap();
  const response = await fetch(url, {
    signal: options.signal, redirect: 'error',
    method: body === undefined ? 'GET' : 'POST', credentials: 'include', cache: 'no-store',
    headers: { Accept: 'application/json', ...(options.idempotencyKey ? { 'Idempotency-Key': options.idempotencyKey } : {}), ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf! }) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const result = await response.json();
  if (!response.ok) {
    if (response.status === 401 || response.status === 419) csrf = undefined;
    if (response.status === 419) {
      await bootstrap(); // Refresh only the handshake; never replay a state-changing request.
      throw new AuthError(419, 'SESSION_CHANGED', 'Your session changed or expired. Please retry.');
    }
    throw new AuthError(response.status, result.error?.code ?? 'API_ERROR', result.error?.message ?? 'Request failed.', result.error?.fields ?? {});
  }
  if (result.data.csrf_token) csrf = result.data.csrf_token;
  if ((path === 'activation/consume' || path === 'recovery/consume')) csrf = undefined;
  if ((path.startsWith('security/sessions/') && path.endsWith('/revoke')) || (path.startsWith('security/mfa/') && result.data.verified) || path === 'login' || path === 'logout' || (path === 'activation/consume' || path === 'recovery/consume')) announceSessionChange();
  return result.data as T;
}
export async function bootstrap() { return browserRequest<{ csrf_token: string }>('csrf'); }
export async function logout() { await browserRequest('logout', {}); csrf = undefined; }
