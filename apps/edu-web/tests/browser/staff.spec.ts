import {test, expect, request, type BrowserContext, type Page, type Request} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {createHmac, randomUUID} from 'node:crypto';
import path from 'node:path';
const api='https://api.edu.test:8443/api/v1/browser';
const origin='https://web.edu.test:3443';
const fixture=path.resolve('../../scripts/browser-fixtures.php');
const password='Synthetic-Browser-Only-Password';
function code(seed:string) {const a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; const bits=[...seed].map(c=>a.indexOf(c).toString(2).padStart(5,'0')).join(''); const key=Buffer.from(bits.match(/.{8}/g)!.map(x=>parseInt(x,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const digest=createHmac('sha1',key).update(counter).digest();const offset=digest[19]&15;return String((digest.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');}
async function apiAdmin(context:BrowserContext, mfa=true) {
 const bootstrap=await context.request.get(`${api}/csrf`,{headers:{Origin:origin}});const csrf=(await bootstrap.json()).data.csrf_token;
 const login=await context.request.post(`${api}/login`,{headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{school_code:'A',identifier:'ADMIN',password}});expect(login.status()).toBe(200);const token=(await login.json()).data.csrf_token;
 if(mfa){const setup=await context.request.post(`${api}/security/mfa/enrollment`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{password}});expect(setup.status()).toBe(200);const seed=(await setup.json()).data.secret;const confirm=await context.request.post(`${api}/security/mfa/enrollment/confirm`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{password,code:code(seed)}});expect(confirm.status()).toBe(200);}
}

function target(): {membership_id: string; name: string} {
  return JSON.parse(execFileSync('php', [fixture, 'staff-profile-target'], {encoding: 'utf8'}));
}

test.beforeEach(() => execFileSync('php', [fixture], {stdio: 'pipe'}));

test('School Admin registers and reads an existing staff membership over HTTPS', async ({page, context}) => {
  const member = target();
  await apiAdmin(context);
  await page.goto('/staff');
  const candidates = page.getByRole('region', {name: 'Eligible memberships'});
  await candidates.getByLabel('Search eligible memberships').fill(member.name);
  await candidates.getByRole('button', {name: 'Search', exact: true}).click();
  await candidates.getByRole('button', {name: 'Register ' + member.name}).click();
  const dialog = page.getByRole('dialog', {name: 'Confirm staff registration'});
  await expect(dialog).toBeVisible();
  const [response] = await Promise.all([
    page.waitForResponse(r => r.url() === api + '/staff' && r.request().method() === 'POST'),
    dialog.getByRole('button', {name: 'Confirm registration'}).click(),
  ]);
  expect(response.status()).toBe(201);
  expect(response.request().postDataJSON()).toEqual({membership_id: member.membership_id});
  const directory = page.getByRole('region', {name: 'Staff directory'});
  await directory.getByLabel('Search profiles').fill(member.name);
  await directory.getByRole('button', {name: 'Search', exact: true}).click();
  await directory.getByRole('button', {name: member.name + ' · Teacher'}).click();
  await expect(page.getByRole('region', {name: 'Staff detail'})).toContainText(member.name);
  execFileSync('php', [fixture, 'expire']);
  await directory.getByRole('button', {name: 'Search', exact: true}).click();
  await expect(page).toHaveURL(/session-expired/);
  await expect(page.getByText(member.name, {exact: false})).toHaveCount(0);
});

test('expired entitlement allows staff reads and prevents registration', async ({page, context}) => {
  const member = target();
  await apiAdmin(context);
  execFileSync('php', [fixture, 'academic-ui-expire']);
  await page.goto('/staff');
  const candidates = page.getByRole('region', {name: 'Eligible memberships'});
  await candidates.getByLabel('Search eligible memberships').fill(member.name);
  await candidates.getByRole('button', {name: 'Search', exact: true}).click();
  await candidates.getByRole('button', {name: 'Register ' + member.name}).click();
  await page.getByRole('dialog').getByRole('button', {name: 'Confirm registration'}).click();
  await expect(candidates.getByText('Read-only access')).toBeVisible();
  await expect(page.getByRole('region', {name: 'Staff directory'})).toBeVisible();
});

const staffUrl = api + '/staff';
const originHeaders = {Origin: origin};

async function openStaffProfile(page: Page, context: BrowserContext) {
  const member = target();
  await apiAdmin(context);
  const csrfResponse = await context.request.get(api + '/csrf', {headers: originHeaders});
  expect(csrfResponse.status()).toBe(200);
  const registration = await context.request.post(staffUrl, {
    headers: {...originHeaders, 'X-CSRF-TOKEN': (await csrfResponse.json()).data.csrf_token, 'Idempotency-Key': randomUUID()},
    data: {membership_id: member.membership_id},
  });
  expect(registration.status()).toBe(201);
  const {id} = (await registration.json()).data.resource;
  const identity = await context.request.get(api + '/me', {headers: originHeaders});
  expect(identity.status()).toBe(200);
  const {school_id: schoolId} = (await identity.json()).data;

  await page.goto('/staff');
  const directory = page.getByRole('region', {name: 'Staff directory'});
  await directory.getByLabel('Search profiles').fill(member.name);
  await directory.getByRole('button', {name: 'Search', exact: true}).click();
  const entry = directory.getByRole('button', {name: member.name + ' · Teacher'});
  await expect(entry).toBeVisible();
  await entry.click();
  await expect(page.getByRole('region', {name: 'Staff detail'})).toContainText(member.name);
  return {name: member.name, id, schoolId, directory};
}

function countStaffRegistrationPosts(page: Page) {
  let count = 0;
  page.on('request', req => {
    if (req.url() === staffUrl && req.method() === 'POST') count++;
  });
  return () => count;
}

async function expectPrivateStaffRemoved(page: Page, name: string) {
  await expect(page.getByText(name, {exact: false})).toHaveCount(0);
  await expect(page.getByRole('region', {name: 'Staff directory'})).toHaveCount(0);
  await expect(page.getByRole('region', {name: 'Staff detail'})).toHaveCount(0);
}

test('school context loss discards a pending Staff response and clears private data', async ({page, context}) => {
  const {name, id, schoolId, directory} = await openStaffProfile(page, context);
  const countPosts = countStaffRegistrationPosts(page);
  const other = await context.newPage();
  const filtered = (url: URL) => url.origin + url.pathname === staffUrl && url.searchParams.get('q') === name;
  let release!: () => void;
  const gate = new Promise<void>(resolve => {release = resolve;});
  let intercepted: Request | undefined;
  let authorized = false;
  let settled = false;
  let routeError: unknown;
  await page.route(filtered, async route => {
    if (route.request().method() !== 'GET' || intercepted) {
      await route.continue();
      return;
    }
    intercepted = route.request();
    try {
      const response = await route.fetch();
      expect(response.status()).toBe(200);
      expect((await response.json()).data.items.some((item: {id: string; display_name: string}) =>
        item.id === id && item.display_name === name)).toBe(true);
      authorized = true;
      await gate;
      try {
        await route.fulfill({response});
      } catch (error) {
        // The workspace may abort the request when its protected children unmount.
        if (!/ERR_ABORTED/.test(intercepted.failure()?.errorText ?? '')) throw error;
      }
    } catch (error) {
      routeError = error;
    } finally {
      settled = true;
    }
  });
  try {
    await directory.getByRole('button', {name: 'Search', exact: true}).click();
    await expect.poll(() => authorized || settled).toBe(true);
    if (routeError) throw routeError;
    expect(authorized).toBe(true);

    await other.goto('/login');
    await other.getByLabel('School code').fill('B');
    await other.getByLabel('Account identifier').fill('TEACHER');
    await other.getByLabel('Password', {exact: true}).fill(password);
    const [login, revalidation] = await Promise.all([
      other.waitForResponse(r => r.url() === api + '/login' && r.request().method() === 'POST'),
      page.waitForResponse(async r => r.url() === api + '/me' && r.ok() && (await r.json()).data.role === 'teacher'),
      other.getByRole('button', {name: 'Sign in', exact: true}).click(),
    ]);
    expect(login.status()).toBe(200);
    const newIdentity = (await revalidation.json()).data;
    expect(newIdentity.school_id).toBe((await login.json()).data.school_id);
    expect(newIdentity.school_id).not.toBe(schoolId);
    expect(newIdentity.role).toBe('teacher');
    await expect(other).toHaveURL(/dashboard\/teacher/);
    await expect(page).toHaveURL(/access-denied/);
    await expectPrivateStaffRemoved(page, name);

    release();
    await expect.poll(() => settled).toBe(true);
    if (routeError) throw routeError;
    await expectPrivateStaffRemoved(page, name);
    const denied = await context.request.get(staffUrl + '/' + id, {headers: originHeaders});
    expect(denied.status()).toBe(403);
    expect((await denied.json()).data).toBeUndefined();
    expect((await denied.text()).includes(name)).toBe(false);
    expect(countPosts()).toBe(0);
  } finally {
    release();
    await page.unroute(filtered);
    await other.close();
  }
});

test('explicit current browser revocation clears Staff and rejects the old session', async ({page, context}) => {
  const {name, id} = await openStaffProfile(page, context);
  const countPosts = countStaffRegistrationPosts(page);
  const other = await context.newPage();
  try {
    await other.goto('/profile/sessions');
    const current = other.getByRole('article').filter({has: other.getByRole('heading', {name: 'Current browser', exact: true})});
    await current.getByLabel('Current password').fill(password);
    const revokedSession = await request.newContext({storageState: await context.storageState(), ignoreHTTPSErrors: true});
    try {
      const before = await revokedSession.get(staffUrl + '/' + id, {headers: originHeaders});
      expect(before.status()).toBe(200);
      expect((await before.json()).data.id).toBe(id);

      other.once('dialog', dialog => dialog.accept());
      const [revoked, revalidation] = await Promise.all([
        other.waitForResponse(r => r.url().startsWith(api + '/security/sessions/') &&
          r.url().endsWith('/revoke') && r.request().method() === 'POST'),
        page.waitForResponse(r => r.url() === api + '/me' && r.status() === 401),
        current.getByRole('button', {name: 'Revoke current browser', exact: true}).click(),
      ]);
      expect(revoked.status()).toBe(200);
      expect((await revoked.json()).data.revoked).toBe(1);
      expect((await revalidation.json()).data).toBeUndefined();
      await expect(other).toHaveURL(/\/login$/);
      await expect(page).toHaveURL(/session-expired/);
      await expectPrivateStaffRemoved(page, name);

      const denied = await revokedSession.get(staffUrl + '/' + id, {headers: originHeaders});
      expect(denied.status()).toBe(401);
      expect((await denied.json()).data).toBeUndefined();
      expect((await denied.text()).includes(name)).toBe(false);
      expect(countPosts()).toBe(0);
    } finally {
      await revokedSession.dispose();
    }
  } finally {
    await other.close();
  }
});
