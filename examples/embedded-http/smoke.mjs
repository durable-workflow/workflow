import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
const base = process.env.BASE_URL;
const profile = process.env.PROFILE_NAME ?? 'fpm';
const receipts = [];
let sequence = 0;
async function request(method, path, user = 'alpha', input, optional) {
  const id = `${profile}-smoke-${++sequence}`;
  const headers = {'Accept': 'application/json', 'Authorization': `Bearer qualification-${user}`, 'X-Request-Id': id};
  if (input) headers['Content-Type'] = 'application/json';
  if (optional) headers['X-Optional-Context'] = optional;
  const response = await fetch(base + path, {method, headers, body: input ? JSON.stringify(input) : undefined, signal: AbortSignal.timeout(20000)});
  const body = await response.text();
  const record = {id, method, path, user, status: response.status, headers: Object.fromEntries(response.headers), body};
  receipts.push(record);
  assert.equal(response.headers.get('x-request-id'), id);
  assert.equal(response.headers.get('x-qualification-user'), user);
  assert.equal(response.headers.get('x-qualification-namespace'), `qualification-${user}`);
  assert.equal(response.headers.get('x-optional-context'), optional ?? '');
  return {...record, json: JSON.parse(body)};
}
try {
  const started = await request('POST', '/api/workflows', 'alpha', {instance_id:'alpha-fast-smoke', mode:'fast'}, 'alpha-only');
  assert.equal(started.status, 202);
  let complete;
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) {
    complete = await request('GET', '/api/workflows/alpha-fast-smoke');
    assert.equal(complete.status, 200);
    if (complete.json.status === 'completed') break;
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  assert.equal(complete.json.status, 'completed');
  assert.equal(complete.json.run_id, started.json.run_id);
  assert.ok(complete.body.includes('"integer":9007199254740993'));
  assert.equal(complete.json.output.owner, 'alpha');
  assert.equal(complete.json.output.activity.optional, null);
  assert.equal(complete.json.output.activity.unicode, 'activity λ');
  const foreign = await request('GET', '/api/workflows/alpha-fast-smoke', 'beta');
  assert.equal(foreign.status, 404);
  const query = await request('GET', '/api/workflows/alpha-fast-smoke/query');
  assert.equal(query.status, 200);
  assert.deepEqual(query.json.snapshot, {owner:'alpha', state:'finished'});
  await writeFile(`/evidence/${profile}-smoke.json`, JSON.stringify({profile, pass:true, original_run_id:started.json.run_id, receipts}, null, 2));
  console.log(JSON.stringify({pass:true, original_run_id:started.json.run_id, requests:receipts.length, pids:[...new Set(receipts.map(r=>r.headers['x-worker-pid']))]}));
} catch (error) {
  await writeFile(`/evidence/${profile}-smoke.json`, JSON.stringify({profile, pass:false, error:String(error), receipts}, null, 2));
  throw error;
}
