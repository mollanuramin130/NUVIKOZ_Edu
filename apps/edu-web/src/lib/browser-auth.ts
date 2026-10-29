export type Identity = { user_id: string; school_id: string; role: string; mfa_required?: boolean };
export class AuthError extends Error {
  constructor(public status: number, public code: string, message: string) { super(message); }
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
export async function browserRequest<T>(path: string, body?: unknown): Promise<T> {
  if (!base) throw new Error('The Edu API origin is not configured.');
  const target = new URL(base);
  const loopback = (host: string) => ['localhost', '127.0.0.1'].includes(host);
  const localHttp = process.env.NODE_ENV === 'development' && process.env.NEXT_PUBLIC_EDU_ALLOW_LOCAL_HTTP === 'true'
    && loopback(target.hostname) && loopback(window.location.hostname);
  if (!localHttp && (target.protocol !== 'https:' || window.location.protocol !== 'https:')) {
    throw new Error('HTTPS is required to sign in securely.');
  }
  if (body !== undefined && !csrf) await bootstrap();
  const response = await fetch(new URL(`/api/v1/browser/${path}`, base), {
    method: body === undefined ? 'GET' : 'POST', credentials: 'include', cache: 'no-store',
    headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf! }) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const result = await response.json();
  if (!response.ok) {
    if (response.status === 401 || response.status === 419) csrf = undefined;
    if (response.status === 419) {
      await bootstrap(); // Refresh only the handshake; never replay a state-changing request.
      throw new AuthError(419, 'SESSION_CHANGED', 'Your session changed or expired. Please retry.');
    }
    throw new AuthError(response.status, result.error?.code ?? 'API_ERROR', result.error?.message ?? 'Request failed.');
  }
  if (result.data.csrf_token) csrf = result.data.csrf_token;
  if ((path === 'activation/consume' || path === 'recovery/consume')) csrf = undefined;
  if ((path.startsWith('security/mfa/') && result.data.verified) || path === 'login' || path === 'logout' || (path === 'activation/consume' || path === 'recovery/consume')) announceSessionChange();
  return result.data as T;
}
export async function bootstrap() { return browserRequest<{ csrf_token: string }>('csrf'); }
export async function logout() { await browserRequest('logout', {}); csrf = undefined; }
