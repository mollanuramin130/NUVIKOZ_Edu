export type Envelope<T> = { data: T; meta: { request_id: string } };
export type Health = { status: 'ok'; api_version: 'v1' };
// Public health contract only. Protected client integration awaits CSRF/session validation.
export async function health(baseUrl: string, signal?: AbortSignal): Promise<Envelope<Health>> {
  const url = new URL('/api/v1/health', baseUrl);
  const response = await fetch(url, { signal, cache: 'no-store', headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`API unavailable (${response.status})`);
  return response.json() as Promise<Envelope<Health>>;
}
