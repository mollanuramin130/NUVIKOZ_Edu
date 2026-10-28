import { test, expect, request as httpRequest, type BrowserContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
const api = 'https://api.edu.test:8443/api/v1/browser';
const origin = 'https://web.edu.test:3443';
const fixture = path.resolve('../../scripts/browser-fixtures.php');
const password = 'Synthetic-Browser-Only-Password';
async function bootstrap(context: BrowserContext) {
  const r = await context.request.get(`${api}/csrf`, { headers: { Origin: origin } });
  expect(r.status()).toBe(200);
  return (await r.json()).data.csrf_token as string;
}
async function login(context: BrowserContext, code = 'A', identifier = 'TEACHER', secret = password) {
  const token = await bootstrap(context);
  return context.request.post(`${api}/login`, { headers: { Origin: origin, 'X-CSRF-TOKEN': token }, data: { school_code: code, identifier, password: secret } });
}
test.beforeEach(() => { execFileSync('php', [fixture], { stdio: 'pipe' }); });
test('CSRF and exact CORS reject missing tokens and hostile origins', async ({ context }) => {
  await bootstrap(context);
  for (const headers of ([{ Origin: origin }, { Origin: origin, 'X-CSRF-TOKEN': 'wrong', 'Sec-Fetch-Site': 'same-origin' }] as Record<string, string>[])) {
    expect((await context.request.post(`${api}/login`, { headers, data: {} })).status()).toBe(419);
  }
  const denied = await context.request.get(`${api}/csrf`, { headers: { Origin: 'https://nursery.edu.test:3443' } });
  expect(denied.status()).toBe(403); expect(denied.headers()['access-control-allow-origin']).toBeUndefined();
  expect((await context.request.get(`${api}/csrf`)).status()).toBe(403);
  const cors = await context.request.fetch(`${api}/login`, { method: 'OPTIONS', headers: { Origin: origin, 'Access-Control-Request-Method': 'POST' } });
  expect(cors.status()).toBe(204); expect(cors.headers()['access-control-allow-origin']).toBe(origin); expect(cors.headers()['access-control-allow-credentials']).toBe('true');
});
test('real Next.js login, role guard and logout use host-only secure cookies', async ({ page, context }) => {
  await page.goto('/login');
  await page.getByLabel('School code').fill('A'); await page.getByLabel('Account identifier').fill('TEACHER'); await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/dashboard\/teacher/); await expect(page.getByRole('heading', { name: 'teacher dashboard' })).toBeVisible();
  const cookie = (await context.cookies()).find(c => c.name === 'edu_browser_session')!;
  expect(cookie).toBeTruthy(); expect(cookie.domain).toBe('api.edu.test'); expect(cookie.httpOnly).toBe(true); expect(cookie.secure).toBe(true); expect(cookie.sameSite).toBe('Lax');
  expect((await context.cookies(origin)).find(c => c.name === 'edu_browser_session')).toBeUndefined();
  expect(await page.evaluate(() => Object.keys(localStorage))).toEqual([]);
  await page.getByRole('button', { name: 'Sign out' }).click(); await expect(page).toHaveURL(/\/login$/);
  await page.goto('/dashboard/teacher'); await expect(page).toHaveURL(/session-expired/);
});
test('invalid credentials, suspension and inactive membership cannot log in', async ({ context }) => {
  expect((await login(context, 'A', 'TEACHER', 'wrong')).status()).toBe(401);
  expect((await login(context, 'S')).status()).toBe(401);
  expect((await login(context, 'A', 'INACTIVE')).status()).toBe(401);
});
test('session fixation, cross-tenant context and logout replay are rejected', async ({ context }) => {
  await bootstrap(context);
  const old = (await context.cookies()).find(c => c.name === 'edu_browser_session')!;
  const result = await login(context); expect(result.status()).toBe(200);
  const identity = (await result.json()).data;
  expect((await context.request.get(`${api}/me`, { headers: { Origin: origin, 'X-School-ID': 'another-school' } })).status()).toBe(403);
  expect((await context.request.get(`${api}/me`, { headers: { Origin: origin, 'X-School-ID': identity.school_id } })).status()).toBe(200);
  const isolated = await httpRequest.newContext({ ignoreHTTPSErrors: true });
  expect((await isolated.get(`${api}/me`, { headers: { Origin: origin, Cookie: `edu_browser_session=${old.value}` } })).status()).toBe(401);
  const live = (await context.cookies()).find(c => c.name === 'edu_browser_session')!;
  expect((await context.request.post(`${api}/logout`, { headers: { Origin: origin, 'X-CSRF-TOKEN': identity.csrf_token }, data: {} })).status()).toBe(200);
  expect((await isolated.get(`${api}/me`, { headers: { Origin: origin, Cookie: `edu_browser_session=${live.value}` } })).status()).toBe(401);
  await isolated.dispose();
});
test('expired database session and wrong role navigation deny access', async ({ page, context }) => {
  expect((await login(context)).status()).toBe(200);
  await page.goto('/dashboard/school_admin'); await expect(page).toHaveURL(/access-denied/);
  execFileSync('php', [fixture, 'expire']);
  expect((await context.request.get(`${api}/me`, { headers: { Origin: origin } })).status()).toBe(401);
});

test('multi-tab login change and logout revalidate other protected tabs', async ({ context, page }) => {
  expect((await login(context)).status()).toBe(200);
  await page.goto('/dashboard/teacher');
  await expect(page.getByRole('heading', { name: 'teacher dashboard' })).toBeVisible();
  const other = await context.newPage();
  await other.goto('/login');
  await other.getByLabel('School code').fill('B');
  await other.getByLabel('Account identifier').fill('TEACHER');
  await other.getByLabel('Password', { exact: true }).fill(password);
  await other.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(other).toHaveURL(/dashboard\/teacher/);
  const identity = (await (await context.request.get(`${api}/me`, { headers: { Origin: origin } })).json()).data;
  await expect(page.getByText(`School context: ${identity.school_id}`, { exact: true })).toBeVisible();
  await other.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/session-expired/);
});
test('stale CSRF refreshes handshake without automatically replaying logout', async ({ page, context }) => {
  await page.goto('/login');
  await page.getByLabel('School code').fill('A');
  await page.getByLabel('Account identifier').fill('TEACHER');
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/dashboard\/teacher/);
  // Another client sharing cookies rotates the session without a broadcast notification.
  const other = await context.newPage();
  await other.goto('/login');
  expect((await login(context)).status()).toBe(200);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('alert')).toContainText('Please retry');
  expect((await context.request.get(`${api}/me`, { headers: { Origin: origin } })).status()).toBe(200);
  await page.getByRole('button', { name: 'Retry sign out' }).click();
  await expect(page).toHaveURL(/\/login$/);
});
for (const change of ['disable-identity', 'disable-membership', 'suspend-school']) {
  test(`existing browser session is revoked after ${change}`, async ({ context }) => {
    expect((await login(context)).status()).toBe(200);
    execFileSync('php', [fixture, change]);
    expect((await context.request.get(`${api}/me`, { headers: { Origin: origin } })).status()).toBe(401);
  });
}
