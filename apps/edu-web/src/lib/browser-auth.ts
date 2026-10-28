export type Identity = { user_id: string; school_id: string; role: string };
export class AuthError extends Error {
  constructor(public status: number, public code: string, message: string) { super(message); }
}
const base = process.env.NEXT_PUBLIC_EDU_API_BASE_URL;
let csrf: string | undefined;
export async function browserRequest<T>(path: string, body?: unknown): Promise<T> {
  if (!base) throw new Error('The Edu API origin is not configured.');
  if (body !== undefined && !csrf) await bootstrap();
  const response = await fetch(new URL(`/api/v1/browser/${path}`, base), {
    method: body === undefined ? 'GET' : 'POST', credentials: 'include', cache: 'no-store',
    headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf! }) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const result = await response.json();
  if (!response.ok) {
    if (response.status === 401 || response.status === 419) csrf = undefined;
    throw new AuthError(response.status, result.error?.code ?? 'API_ERROR', result.error?.message ?? 'Request failed.');
  }
  if (result.data.csrf_token) csrf = result.data.csrf_token;
  return result.data as T;
}
export async function bootstrap() { return browserRequest<{ csrf_token: string }>('csrf'); }
export async function logout() { await browserRequest('logout', {}); csrf = undefined; }
