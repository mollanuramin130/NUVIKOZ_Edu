import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import ts from 'typescript';
import {webcrypto} from 'node:crypto';
function fixture(handler) {
  const requests = [];
  const context = vm.createContext({URL, AbortController, crypto: webcrypto, process: {env: {NEXT_PUBLIC_EDU_API_BASE_URL: 'https://api.edu.test', NODE_ENV: 'production'}}, window: {location: {protocol: 'https:', hostname: 'web.edu.test'}}, BroadcastChannel: class {postMessage() {} close() {}}, fetch: async (url, options) => {requests.push({url: String(url), options}); return handler(String(url), options);}});
  function load(file, imports = {}) {
    const loaded = {exports: {}};
    const source = ts.transpileModule(fs.readFileSync(path.resolve('src/lib', file), 'utf8'), {compilerOptions: {module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020}}).outputText;
    context.module = loaded; context.exports = loaded.exports; context.require = name => {if (!imports[name]) throw Error('Unexpected import'); return imports[name];};
    vm.runInContext(source, context); return loaded.exports;
  }
  const auth = load('browser-auth.ts');
  const api = load('academic-api.ts', {'./browser-auth': auth});
  return {auth, api, requests, context};
}
const response = (status, data = {}, error = {}) => ({ok: status < 400, status, json: async () => ({data, error})});
test('academic filters are encoded and GET retains secure cookie/no-store/no-redirect boundary', async () => {
  const f = fixture(() => response(200, {items: [], next_cursor: null}));
  const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
  await f.api.listAcademic('class-offerings', {session_id: id, cursor: id});
  const r = f.requests[0]; assert.equal(new URL(r.url).searchParams.get('session_id'), id); assert.equal(r.options.credentials, 'include'); assert.equal(r.options.cache, 'no-store'); assert.equal(r.options.redirect, 'error'); assert.equal(r.options.method, 'GET');
});
test('command bootstraps CSRF and sends a boolean with an explicit idempotency key', async () => {
  const f = fixture(url => response(200, url.endsWith('/csrf') ? {csrf_token: 'synthetic-csrf'} : {resource: {}, replayed: false}));
  const key = f.api.newAcademicIntent(); assert.match(key, /^[A-Za-z0-9_-]{16,160}$/);
  await f.api.createAcademic('subject-offerings', {class_offering_id:'01ARZ3NDEKTSV4RRFFQ69G5FAV', subject_id:'01ARZ3NDEKTSV4RRFFQ69G5FAV', compulsory:false}, key);
  assert.equal(f.requests.length, 2); const r=f.requests[1]; assert.equal(r.options.headers['Idempotency-Key'], key); assert.equal(r.options.headers['X-CSRF-TOKEN'], 'synthetic-csrf'); assert.equal(JSON.parse(r.options.body).compulsory, false);
});
test('419 refreshes handshake exactly once without replaying the mutation', async () => {
  const f = fixture(url => url.endsWith('/csrf') ? response(200, {csrf_token:'synthetic'}) : response(419));
  await assert.rejects(f.api.createAcademic('subjects', {code:'T', name:'Test'}, 'synthetic-command-01'), e => e.status===419);
  assert.equal(f.requests.filter(r=>r.options.method==='POST').length, 1); assert.equal(f.requests.filter(r=>r.url.endsWith('/csrf')).length, 2);
});
test('409 and 422 preserve errors and do not silently retry', async () => {
  for (const status of [409,422]) {
    const f=fixture(url=>url.endsWith('/csrf') ? response(200,{csrf_token:'synthetic'}) : response(status, {}, {code:'VALIDATION_FAILED', fields:{name:['Synthetic field error']}}));
    await assert.rejects(f.api.createAcademic('classes',{name:'X',sort_order:0},'synthetic-command-02'), e=>e.status===status&&e.fields.name[0]==='Synthetic field error');
    assert.equal(f.requests.filter(r=>r.options.method==='POST').length,1);
  }
});
test('untrusted paths, injected queries and invalid command keys never reach fetch', async () => {
  const f=fixture(()=>response(200));
  for (const route of ['https://hostile.invalid', '//hostile.invalid', '../me', 'academic/subjects?school_id=X', 'academic/%2e%2e/me', 'academic/subjects#fragment']) await assert.rejects(f.auth.browserRequest(route));
  await assert.rejects(f.auth.browserRequest('academic/subjects', undefined, {query:{school_id:'01ARZ3NDEKTSV4RRFFQ69G5FAV'}}));
  await assert.rejects(f.api.listAcademic('sections',{class_offering_id:'invalid&school_id=B'}));
  await assert.rejects(f.api.createAcademic('subjects',{name:'X',code:'X'},'short'));
  assert.equal(f.requests.length,0);
});
test('production HTTP refuses even an explicitly configured loopback exception', async () => {
  const f=fixture(()=>response(200)); f.context.process.env.NEXT_PUBLIC_EDU_API_BASE_URL='http://localhost:8000'; f.context.process.env.NEXT_PUBLIC_EDU_ALLOW_LOCAL_HTTP='true'; f.context.window.location={protocol:'http:',hostname:'localhost'};
  await assert.rejects(f.auth.browserRequest('me'), /HTTPS/); assert.equal(f.requests.length,0);
});
test('request cancellation is passed to fetch without storing browser credentials', async () => {
  const f=fixture(()=>response(200,{items:[],next_cursor:null})); const c=new AbortController(); await f.api.listAcademic('subjects',{},c.signal); assert.equal(f.requests[0].options.signal,c.signal); assert.equal(f.context.localStorage,undefined);
});
