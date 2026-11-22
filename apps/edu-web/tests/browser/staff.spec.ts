import {test, expect, type BrowserContext} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
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
