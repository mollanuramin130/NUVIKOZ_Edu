import { test, expect, request as httpRequest, type BrowserContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import {createHmac} from 'node:crypto';
function totp(seed:string) { const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; const bits=[...seed].map(c=>alphabet.indexOf(c).toString(2).padStart(5,'0')).join(''); const key=Buffer.from(bits.match(/.{8}/g)!.map(b=>parseInt(b,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const digest=createHmac('sha1',key).update(counter).digest();const offset=digest[19]&15;return String((digest.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0'); }
const api = 'https://api.edu.test:8443/api/v1/browser';
const origin = 'https://web.edu.test:3443';
const fixture = path.resolve('../../scripts/browser-fixtures.php');
const password = 'Synthetic-Browser-Only-Password';
async function bootstrap(context: BrowserContext) {
  const r = await context.request.get(`${api}/csrf`, { headers: { Origin: origin } });
  expect(r.status()).toBe(200);
  return (await r.json()).data.csrf_token as string;
}
async function login(context: BrowserContext, code = 'A', identifier = 'TEACHER', secret = password, autoMfa = true) {
  const token = await bootstrap(context);
  const response=await context.request.post(`${api}/login`, { headers: { Origin: origin, 'X-CSRF-TOKEN': token }, data: { school_code: code, identifier, password: secret } });
  if(identifier==='ADMIN' && autoMfa && response.status()===200){
    const csrf=(await response.json()).data.csrf_token;
    const setup=await context.request.post(`${api}/security/mfa/enrollment`,{headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{password:secret}});expect(setup.status()).toBe(200);
    const result=await setup.json();
    const confirm=await context.request.post(`${api}/security/mfa/enrollment/confirm`,{headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{password:secret,code:totp(result.data.secret)}});expect(confirm.status()).toBe(200);
  }
  return response;
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
  await expect(page.getByRole('main').getByRole('alert')).toContainText('Please retry');
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
test('production web refuses credential submission from HTTP before contacting API', async ({ page }) => {
  const apiRequests: string[] = [];
  page.on('request', request => { if (request.url().startsWith(api)) apiRequests.push(request.url()); });
  await page.goto('http://web.edu.test:3000/login');
  await page.getByLabel('School code').fill('A');
  await page.getByLabel('Account identifier').fill('TEACHER');
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('HTTPS is required');
  expect(apiRequests).toEqual([]);
});

test('authorized provisioning hands a single-use activation into secure browser login', async ({ context, page }) => {
 const adminLogin=await login(context,'A','ADMIN');expect(adminLogin.status()).toBe(200);
 const admin= (await adminLogin.json()).data;admin.csrf_token=await bootstrap(context);
 const identifier=`ACT_${Date.now()}`;
 const provision=await context.request.post(`${api}/accounts`,{headers:{Origin:origin,'X-CSRF-TOKEN':admin.csrf_token,'Idempotency-Key':`activation-${Date.now()}`},data:{name:'Synthetic activation user',login_identifier:identifier,role:'teacher'}});
 expect(provision.status()).toBe(201);const result=(await provision.json()).data;
 expect(result.one_time_password).toBeUndefined();const proof=result.local_handover;
 await context.request.post(`${api}/logout`,{headers:{Origin:origin,'X-CSRF-TOKEN':admin.csrf_token},data:{}});
 expect((await login(context,'A',identifier,password)).status()).toBe(401);
 await page.goto('/activate');
 await page.getByLabel('School code').fill('A');await page.getByLabel('Activation ID').fill(proof.proof_id);await page.getByLabel('Activation secret').fill('0'.repeat(64));
 await page.getByLabel('New password',{exact:true}).fill(password);await page.getByLabel('Confirm password').fill(password);
 await page.getByRole('button',{name:'Activate account',exact:true}).click();
 await expect(page.getByRole('main').getByRole('alert')).toContainText('invalid, expired');
 await page.getByLabel('Activation secret').fill(proof.secret);await page.getByRole('button',{name:'Activate account',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Account activated',exact:true})).toBeVisible();
 const token=await bootstrap(context);
 const replay=await context.request.post(`${api}/activation/consume`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{school_code:'A',proof_id:proof.proof_id,secret:proof.secret,password,password_confirmation:password}});expect(replay.status()).toBe(422);
 await page.getByRole('link',{name:'Continue to sign in'}).click();await page.getByLabel('School code').fill('A');await page.getByLabel('Account identifier').fill(identifier);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page).toHaveURL(/dashboard\/teacher/);
 expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);
});

test('generic recovery requests preserve CSRF and origin protection without enumeration', async ({ context, page }) => {
  const csrf = await bootstrap(context);
  expect((await context.request.post(`${api}/recovery/request`, {headers:{Origin:origin},data:{school_code:'A',identifier:'TEACHER'}})).status()).toBe(419);
  expect((await context.request.post(`${api}/recovery/consume`, {headers:{Origin:'https://hostile.edu.test','X-CSRF-TOKEN':csrf},data:{}})).status()).toBe(403);
  const results: unknown[] = [];
  for(const identifier of ['TEACHER','UNKNOWN','shared-phone','unverified@example.test','OTHER']) {
    const result = await context.request.post(`${api}/recovery/request`, {headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{school_code:'A',identifier}});
    expect(result.status()).toBe(200); results.push((await result.json()).data);
  }
  expect(results.every(result => JSON.stringify(result) === JSON.stringify(results[0]))).toBe(true);
  expect((await context.request.post(`${api}/recovery/request`, {headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{school_code:'A',identifier:'UNKNOWN'}})).status()).toBe(429);
  // Reset only the isolated fixture/throttle store before exercising the actual UI.
  execFileSync('php',[fixture]);
  await page.goto('/forgot-password');
  await page.getByLabel('School code').fill('A'); await page.getByLabel('Account identifier').fill('UNKNOWN');
  await page.getByRole('button',{name:'Request recovery',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Recovery request received'})).toBeVisible();
  await expect(page.getByText('Automated contact delivery is not available yet. No email or message has been sent.')).toBeVisible();
});

test('assisted recovery resets through HTTPS UI and revokes another browser session', async ({ context, page, browser }) => {
  const holder = await browser.newContext({ignoreHTTPSErrors:true});
  expect((await login(holder)).status()).toBe(200);
  const oldPage = await holder.newPage(); await oldPage.goto('https://web.edu.test:3443/dashboard/teacher');
  await expect(oldPage.getByRole('heading',{name:'teacher dashboard'})).toBeVisible();
  const admin = await login(context,'A','ADMIN'); expect(admin.status()).toBe(200);
  const fixtureTarget = execFileSync('php',[fixture,'recovery-target'],{encoding:'utf8'}).trim();
  await page.goto('/assisted-recovery');
  await page.getByLabel('Target membership ID').fill(fixtureTarget);
  await page.getByLabel('Verification case reference').fill('synthetic-browser-case-0001');
  await page.getByRole('checkbox').check(); await page.getByRole('button',{name:'Authorize one-time recovery'}).click();
  const handover=page.getByRole('region',{name:'One-time local recovery handover'});
  await expect(handover).toBeVisible();
  const values=await handover.locator('dd').allTextContents();
  const proof={proof_id:values[0],secret:values[1]};
  const adminData=(await admin.json()).data;adminData.csrf_token=await bootstrap(context);
  await context.request.post(`${api}/logout`,{headers:{Origin:origin,'X-CSRF-TOKEN':adminData.csrf_token},data:{}});
  await page.goto('/reset-password');
  await page.getByLabel('School code').fill('A'); await page.getByLabel('Recovery ID').fill(proof.proof_id);
  await page.getByLabel('Recovery secret').fill('0'.repeat(64));
  const newPassword='Synthetic-Recovered-Browser-Password';
  await page.getByLabel('New password',{exact:true}).fill(newPassword); await page.getByLabel('Confirm password').fill(newPassword);
  await page.getByRole('button',{name:'Reset password',exact:true}).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('invalid, expired');
  await page.getByLabel('Recovery secret').fill(proof.secret); await page.getByRole('button',{name:'Reset password',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Password reset',exact:true})).toBeVisible();
  expect((await holder.request.get(`${api}/me`,{headers:{Origin:origin}})).status()).toBe(401);
  const csrf=await bootstrap(context);
  expect((await context.request.post(`${api}/recovery/consume`,{headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{school_code:'A',...proof,password:newPassword,password_confirmation:newPassword}})).status()).toBe(422);
  expect((await login(context,'A','TEACHER',newPassword)).status()).toBe(200);
  expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);
  await holder.close();
});

test('School Admin cannot provision before actual MFA enrollment and recovery challenge',async({context,page})=>{
 const response=await login(context,'A','ADMIN',password,false);expect(response.status()).toBe(200);
 const data=(await response.json()).data;expect(data.mfa_required).toBe(true);
 const denied=await context.request.post(`${api}/accounts`,{headers:{Origin:origin,'X-CSRF-TOKEN':data.csrf_token,'Idempotency-Key':'synthetic-mfa-denied-0001'},data:{name:'Denied',role:'teacher',login_identifier:'DENIED'}});expect(denied.status()).toBe(403);
 await page.goto('/dashboard/school_admin');await expect(page).toHaveURL(/profile\/security/);
 await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByRole('button',{name:'Begin enrollment',exact:true}).click();
 const seed=await page.getByTestId('mfa-seed').innerText();
 await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByLabel('Authenticator code').fill(totp(seed));
 await page.getByRole('button',{name:'Confirm enrollment',exact:true}).click();
 await expect(page.getByRole('region',{name:'New recovery codes'})).toBeVisible();
 const codes=await page.getByRole('region',{name:'New recovery codes'}).locator('li').allTextContents();expect(codes.length).toBe(8);
 const csrf=await bootstrap(context);
 expect((await context.request.post(`${api}/security/mfa/challenge`,{headers:{Origin:origin,'X-CSRF-TOKEN':csrf},data:{password,code:totp(seed)}})).status()).toBe(422);
 await page.getByRole('link',{name:'Continue to dashboard'}).click();await expect(page).toHaveURL(/dashboard\/school_admin/);
 await page.getByRole('button',{name:'Sign out',exact:true}).click();
 await page.getByLabel('School code').fill('A');await page.getByLabel('Account identifier').fill('ADMIN');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page).toHaveURL(/profile\/security/);
 const recovery=page.locator('form').filter({has:page.getByRole('button',{name:'Verify recovery code',exact:true})});
 await recovery.getByLabel('Current password',{exact:true}).fill(password);await recovery.getByLabel('Recovery code',{exact:true}).fill(codes[0]);await recovery.getByRole('button',{name:'Verify recovery code',exact:true}).click();
 await expect(page.getByRole('link',{name:'Continue to dashboard'})).toBeVisible();
 const token=await bootstrap(context);expect((await context.request.post(`${api}/security/mfa/recovery-code`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{password,code:codes[0]}})).status()).toBe(422);
 expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);
});
