import {test, expect, type BrowserContext, type Page} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
import {mkdirSync} from 'node:fs';
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
async function signInUI(page:Page, school='A', identifier='ADMIN') {await page.goto('/login');await page.getByLabel('School code').fill(school);await page.getByLabel('Account identifier').fill(identifier);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();}
async function navigate(page:Page, name:string) {await page.getByRole('navigation',{name:'Academic navigation'}).getByRole('link',{name,exact:true}).click();}
async function createSubject(page:Page, value:string) {await page.getByLabel('Subject code',{exact:true}).fill(value);await page.getByLabel('Subject name',{exact:true}).fill(`Synthetic ${value}`);await page.getByRole('button',{name:'Create subject',exact:true}).click();}
test.beforeEach(()=>execFileSync('php',[fixture],{stdio:'pipe'}));

test('complete academic workflow uses actual login MFA and every supported UI command',async({page})=>{
 test.setTimeout(90000);await page.setViewportSize({width:1440,height:1040});
 await signInUI(page);await expect(page).toHaveURL(/profile\/security/);
 await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByRole('button',{name:'Begin enrollment',exact:true}).click();
 const seed=(await page.getByTestId('mfa-seed').textContent())!;
 await page.getByLabel('Current password',{exact:true}).fill(password);await page.getByLabel('Authenticator code').fill(code(seed));await page.getByRole('button',{name:'Confirm enrollment',exact:true}).click();
 await page.getByRole('link',{name:'Continue to dashboard'}).click();await page.getByRole('link',{name:'Academic setup',exact:true}).click();
 await expect(page.getByRole('heading',{level:1})).toHaveText('A thoughtful start to every academic year.');
 await navigate(page,'Sessions');await page.getByLabel('Session name').fill('UI Academic Year');await page.getByLabel('Start date').fill('2080-04-01');await page.getByLabel('End date').fill('2081-03-31');await page.getByRole('button',{name:'Create session',exact:true}).click();await expect(page.getByRole('row').filter({hasText:'UI Academic Year'})).toBeVisible();
 await navigate(page,'Classes & offerings');await page.getByLabel('Class name',{exact:true}).fill('UI Senior Class');await page.getByLabel('Grade label (optional)').fill('XII');await page.getByLabel('Sort order').fill('12');await page.getByRole('button',{name:'Create class',exact:true}).click();await expect(page.getByRole('row').filter({hasText:'UI Senior Class'})).toBeVisible();
 await page.getByLabel('Academic session',{exact:true}).selectOption({label:'UI Academic Year · future'});await page.getByLabel('School class',{exact:true}).selectOption({label:'UI Senior Class'});await page.getByRole('button',{name:'Create class offering',exact:true}).click();await expect(page.getByRole('region',{name:'Session class offerings',exact:true}).getByRole('row').filter({hasText:'UI Senior Class'})).toBeVisible();
 await navigate(page,'Sections');await page.getByLabel('Class offering',{exact:true}).selectOption({label:'UI Senior Class'});await page.getByLabel('Section name',{exact:true}).fill('UI Section A');await page.getByRole('button',{name:'Create section',exact:true}).click();await expect(page.getByRole('row').filter({hasText:'UI Section A'})).toBeVisible();
 await expect(page.getByRole('button',{name:'Assign Teacher',exact:true})).toHaveCount(0);
 mkdirSync(path.resolve('../../artifacts/academic-ui'),{recursive:true});
 const desktop=await page.screenshot({path:path.resolve('../../artifacts/academic-ui/sections-desktop.png'),fullPage:true});
 console.log(`ACADEMIC_UI_DESKTOP_BASE64:${desktop.toString('base64')}`);
 await navigate(page,'Subjects');await createSubject(page,'UI_MATH');await expect(page.getByRole('row').filter({hasText:'UI_MATH'})).toBeVisible();await createSubject(page,'UI_ART');await expect(page.getByRole('row').filter({hasText:'UI_ART'})).toBeVisible();
 await page.getByLabel('Class offering',{exact:true}).selectOption({label:'UI Senior Class'});await page.getByLabel('Subject to assign',{exact:true}).selectOption({label:'UI_MATH · Synthetic UI_MATH'});await page.getByLabel('Subject classification',{exact:true}).selectOption('compulsory');await page.getByRole('button',{name:'Assign subject',exact:true}).click();await expect(page.getByRole('row').filter({hasText:'Synthetic UI_MATH'}).filter({hasText:'Compulsory subject'})).toBeVisible();
 await page.getByLabel('Subject to assign',{exact:true}).selectOption({label:'UI_ART · Synthetic UI_ART'});await page.getByLabel('Subject classification',{exact:true}).selectOption('optional');await page.getByRole('button',{name:'Assign subject',exact:true}).click();await expect(page.getByRole('row').filter({hasText:'Synthetic UI_ART'}).filter({hasText:'Optional subject'})).toBeVisible();
 await page.setViewportSize({width:390,height:844});await page.getByRole('button',{name:'Toggle academic navigation'}).click();await navigate(page,'Sections');await page.getByLabel('Class offering',{exact:true}).selectOption({label:'UI Senior Class'});await expect(page.getByRole('row').filter({hasText:'UI Section A'})).toBeVisible();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
 expect(await page.locator('main input, main select').evaluateAll(elements=>elements.every(e=>(e as HTMLInputElement).labels?.length))).toBe(true);
 expect(await page.getByRole('button',{name:'Create section',exact:true}).evaluate(e=>e.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
 await page.getByLabel('Section name',{exact:true}).focus();expect(await page.getByLabel('Section name',{exact:true}).evaluate(e=>getComputedStyle(e).outlineStyle)).not.toBe('none');
 const mobile=await page.screenshot({path:path.resolve('../../artifacts/academic-ui/sections-mobile.png'),fullPage:true});console.log(`ACADEMIC_UI_MOBILE_BASE64:${mobile.toString('base64')}`);
 expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);
});

test('academic workspace denies guests wrong roles and pending MFA without exposing lists',async({page,context})=>{
 await page.goto('/academic/subjects');await expect(page).toHaveURL(/session-expired/);await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toHaveCount(0);
 await signInUI(page,'B','TEACHER');await expect(page).toHaveURL(/dashboard\/teacher/);await page.goto('/academic');await expect(page).toHaveURL(/access-denied/);
 await apiAdmin(context,false);await page.goto('/academic/subjects');await expect(page).toHaveURL(/profile\/security/);await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toHaveCount(0);
});

test('lost acknowledgement retains the command intent and double submit creates exactly one subject',async({page,context})=>{
 await apiAdmin(context);await page.goto('/academic/subjects');await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toBeVisible();
 let count=0;const keys:string[]=[];
 await page.route('**/api/v1/browser/academic/subjects',async route=>{if(route.request().method()!=='POST'){await route.continue();return;}count++;keys.push(route.request().headers()['idempotency-key']);if(count===1){const r=await route.fetch();expect(r.status()).toBe(200);await route.abort('failed');}else await route.continue();});
 await page.getByLabel('Subject code',{exact:true}).fill('ACK_UI');await page.getByLabel('Subject name',{exact:true}).fill('Synthetic ACK_UI');
 await page.getByRole('button',{name:'Create subject',exact:true}).evaluate(e=>{const f=e.closest('form')!;f.requestSubmit();f.requestSubmit();});
 await expect(page.getByRole('alert')).toContainText('could not confirm');expect(count).toBe(1);
 await page.getByRole('button',{name:'Create subject',exact:true}).click();await expect(page.getByRole('status')).toContainText('existing committed record');expect(count).toBe(2);expect(keys[1]).toBe(keys[0]);await expect(page.getByRole('row').filter({hasText:'ACK_UI'})).toHaveCount(1);
});

test('actual CSRF denial refreshes the workspace and never replays the unsafe UI command',async({page,context})=>{
 await apiAdmin(context);await page.goto('/academic/subjects');await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toBeVisible();let posts=0;
 await page.route('**/api/v1/browser/academic/subjects',async route=>{if(route.request().method()==='POST'){posts++;await route.continue({headers:{...route.request().headers(),'x-csrf-token':'synthetic-invalid-csrf'}});}else await route.continue();});
 await createSubject(page,'CSRF_UI');await expect(page.getByText('Your CSRF or session changed. No command was replayed. Review and submit again.',{exact:true})).toBeVisible();await expect(page.getByLabel('Subject code',{exact:true})).toBeVisible();expect(posts).toBe(1);
 await page.unroute('**/api/v1/browser/academic/subjects');await createSubject(page,'CSRF_UI');await expect(page.getByRole('row').filter({hasText:'CSRF_UI'})).toHaveCount(1);
});

test('expired entitlement denies UI writes while authorized historical reads remain available',async({page,context})=>{
 await apiAdmin(context);await page.goto('/academic/subjects');await createSubject(page,'EXPIRY_BASE');await expect(page.getByRole('row').filter({hasText:'EXPIRY_BASE'})).toBeVisible();execFileSync('php',[fixture,'academic-ui-expire']);
 await createSubject(page,'EXPIRY_DENIED');await expect(page.getByText('That operation is unavailable under current school eligibility or configuration. Authorized reading may remain available.',{exact:true})).toBeVisible();await expect(page.getByRole('row').filter({hasText:'EXPIRY_BASE'})).toBeVisible();await expect(page.getByRole('row').filter({hasText:'EXPIRY_DENIED'})).toHaveCount(0);
});

test('actual UI cursor pagination search empty states and duplicate conflict require deliberate review',async({page,context})=>{
 execFileSync('php',[fixture,'academic-ui-pagination']);await apiAdmin(context);await page.goto('/academic/classes');const catalog=page.getByRole('region',{name:'School class catalog',exact:true});await expect(catalog.getByText('25 loaded records · page 1',{exact:true})).toBeVisible();await catalog.getByRole('button',{name:'Next page',exact:true}).click();await expect(catalog.getByRole('row').filter({hasText:'Pagination Class 25'})).toBeVisible();await catalog.getByLabel('Search this loaded page').fill('not-a-real-class');await expect(catalog.getByRole('heading',{name:'No matches on this page'})).toBeVisible();await catalog.getByLabel('Search this loaded page').fill('');await catalog.getByRole('button',{name:'Previous page',exact:true}).click();await expect(catalog.getByText('25 loaded records · page 1',{exact:true})).toBeVisible();
 await page.getByLabel('Class name',{exact:true}).fill('Pagination Class 00');await page.getByRole('button',{name:'Create class',exact:true}).click();await expect(page.getByRole('alert')).toContainText('Refresh and review');await expect(page.getByRole('alert').getByRole('button',{name:'Refresh records'})).toBeVisible();
});

test('another tab context change discards a delayed private response and clears old academic state',async({page,context})=>{
 await apiAdmin(context);let release:()=>void=()=>{};let held=false;
 await page.route('**/api/v1/browser/academic/subjects',async route=>{if(route.request().method()!=='GET'){await route.continue();return;}const response=await route.fetch();held=true;await new Promise<void>(resolve=>{release=resolve;});await route.fulfill({response}).catch(()=>{});});
 await page.goto('/academic/subjects');await expect.poll(()=>held).toBe(true);
 const other=await context.newPage();await signInUI(other,'B','TEACHER');await expect(other).toHaveURL(/dashboard\/teacher/);await expect(page).toHaveURL(/access-denied/);release();await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toHaveCount(0);expect(await page.evaluate(()=>Object.keys(localStorage))).toEqual([]);await other.close();
});

test('revoked School Admin membership removes the actual academic workspace on revalidation',async({page,context})=>{
 await apiAdmin(context);await page.goto('/academic/subjects');await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toBeVisible();execFileSync('php',[fixture,'academic-ui-revoke']);await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await expect(page).toHaveURL(/session-expired/);await expect(page.getByRole('heading',{name:'Subjects & assignments'})).toHaveCount(0);
});
