import {test,expect,request as httpRequest,type BrowserContext,type Page} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
import path from 'node:path';
const fixture=path.resolve('../../scripts/platform-browser-fixtures.php');
const api='https://api.edu.test:8443/api/v1/browser', origin='https://web.edu.test:3443',password='Synthetic-Platform-Password';
const command=(arg:string)=>execFileSync('php',[fixture,arg],{stdio:'pipe'}).toString().trim();
function totp(seed:string){const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';const bits=[...seed].map(c=>chars.indexOf(c).toString(2).padStart(5,'0')).join('');const key=Buffer.from(bits.match(/.{8}/g)!.map(b=>parseInt(b,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const hash=createHmac('sha1',key).update(counter).digest();return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');}
async function login(page:Page,id:string){await page.goto('/platform');await page.getByLabel('Platform identifier').fill(id);await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in to platform',exact:true}).click();await expect(page.getByRole('heading',{name:'Pending MFA authentication'})).toBeVisible();}
async function csrf(context:BrowserContext){const r=await context.request.get(`${api}/csrf`,{headers:{Origin:origin}});expect(r.status()).toBe(200);return(await r.json()).data.csrf_token as string;}
async function post(context:BrowserContext,route:string,data:unknown){return context.request.post(`${api}/platform/${route}`,{headers:{Origin:origin,'X-CSRF-TOKEN':await csrf(context)},data});}
async function enroll(page:Page){await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByRole('button',{name:'Begin authenticator enrollment'}).click();const seed=await page.getByTestId('platform-seed').textContent();await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByLabel('Authenticator code').fill(totp(seed!));await page.getByRole('button',{name:'Confirm authenticator',exact:true}).click();await expect(page.getByRole('heading',{name:'Verified platform identity'})).toBeVisible();await expect(page.getByRole('region',{name:'Platform recovery codes'}).getByRole('listitem')).toHaveCount(8);return page.getByRole('region',{name:'Platform recovery codes'}).getByRole('listitem').allTextContents();}
let id:string;
test.beforeEach(()=>{id=command('reset');});
test('platform UI enrolls MFA and binds rotated host-only HTTPS cookie',async({page,context})=>{
 await login(page,id);expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin}})).status()).toBe(403);
 const pending=(await context.cookies()).find(c=>c.name==='edu_browser_session')!;
 const codes=await enroll(page);expect(codes).toHaveLength(8);
 const current=(await context.cookies()).find(c=>c.name==='edu_browser_session')!;expect(current.value).not.toBe(pending.value);expect(current.domain).toBe('api.edu.test');expect(current.httpOnly&&current.secure).toBe(true);expect(current.sameSite).toBe('Lax');
 expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin}})).status()).toBe(200);expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);
 await page.getByRole('button',{name:'Sign out of platform'}).click();await expect(page.getByRole('heading',{name:'Platform sign-in'})).toBeVisible();
 const isolated=await httpRequest.newContext({ignoreHTTPSErrors:true});for(const c of[pending,current])expect((await isolated.get(`${api}/platform/identity`,{headers:{Origin:origin,Cookie:`edu_browser_session=${c.value}`}})).status()).toBe(401);await isolated.dispose();
});
test('existing authenticator UI denies wrong code then verifies real TOTP',async({page,context})=>{
 command('enrolled');const seed=command('seed');await login(page,id);const first=page.locator('form').filter({has:page.getByRole('button',{name:'Verify authenticator',exact:true})});
 await first.getByLabel('Current password').fill(password);await first.getByLabel('Authenticator code').fill('wrong'); // Invalid HTML input cannot submit.
 await first.getByLabel('Authenticator code').fill(totp(seed)==='000000'?'000001':'000000');await first.getByRole('button').click();await expect(page.getByRole('alert')).toBeVisible();expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin}})).status()).toBe(403);
 await first.getByLabel('Authenticator code').fill(totp(seed));await first.getByRole('button').click();await expect(page.getByRole('heading',{name:'Verified platform identity'})).toBeVisible();
});
test('recovery challenge is single-use across actual browser sessions',async({page,context})=>{
 await login(page,id);const codes=await enroll(page);await page.getByRole('button',{name:'Sign out of platform'}).click();await expect(page.getByRole('heading',{name:'Platform sign-in'})).toBeVisible();await login(page,id);
 const form=page.locator('form').filter({has:page.getByRole('button',{name:'Verify recovery code',exact:true})});await form.getByLabel('Current password').fill(password);await form.getByLabel('Recovery code',{exact:true}).fill(codes[0]);await form.getByRole('button').click();await expect(page.getByRole('heading',{name:'Verified platform identity'})).toBeVisible();
 await page.getByRole('button',{name:'Sign out of platform'}).click();await expect(page.getByRole('heading',{name:'Platform sign-in'})).toBeVisible();await login(page,id);expect((await post(context,'mfa/recovery',{password,code:codes[0]})).status()).toBe(422);expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin}})).status()).toBe(403);
});
test('expired platform assurance is denied despite active browser cookie',async({page,context})=>{await login(page,id);await enroll(page);command('expire-assurance');expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin}})).status()).toBe(403);await page.getByRole('button',{name:'Refresh identity'}).click();await expect(page.getByRole('heading',{name:'Pending MFA authentication'})).toBeVisible();await expect(page.getByRole('heading',{name:'Verified platform identity'})).toHaveCount(0);});
test('platform browser boundary rejects CSRF, hostile origins, bearer and school context',async({context,page})=>{
 await login(page,id);for(const token of['','invalid'])expect((await context.request.post(`${api}/platform/mfa/enrollment`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{password}})).status()).toBe(419);
 expect((await context.request.get(`${api}/platform/status`,{headers:{Origin:'https://hostile.invalid'}})).status()).toBe(403);
 expect((await context.request.get(`${api}/platform/identity`,{headers:{Origin:origin,Authorization:'Bearer synthetic'}})).status()).toBe(403);
 expect((await context.request.get(`${api}/platform/status`,{headers:{Origin:origin,'X-School-ID':'school'}})).status()).toBe(403);
 await enroll(page);expect((await context.request.get(`${api}/me`,{headers:{Origin:origin}})).status()).toBe(401);
});

test('school session cannot enter platform context and production issuer denies HTTPS login',async({context})=>{
 execFileSync('php',[path.resolve('../../scripts/browser-fixtures.php')],{stdio:'pipe'});
 const token=await csrf(context);
 expect((await context.request.post(`${api}/login`,{headers:{Origin:origin,'X-CSRF-TOKEN':token},data:{school_code:'A',identifier:'TEACHER',password:'Synthetic-Browser-Only-Password'}})).status()).toBe(200);
 expect((await context.request.get(`${api}/platform/status`,{headers:{Origin:origin}})).status()).toBe(403);
 expect((await post(context,'login',{identifier:id,password})).status()).toBe(403);
 const isolated=await httpRequest.newContext({ignoreHTTPSErrors:true});const disabled='https://api.edu.test:8444/api/v1/browser';
 const boot=await isolated.get(`${disabled}/csrf`,{headers:{Origin:origin}});expect(boot.status()).toBe(200);
 expect((await isolated.post(`${disabled}/platform/login`,{headers:{Origin:origin,'X-CSRF-TOKEN':(await boot.json()).data.csrf_token},data:{identifier:id,password}})).status()).toBe(403);
 await isolated.dispose();
});
