import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
const base = process.env.BASE_URL;
const profile = process.env.PROFILE_NAME;
const receipts = [];
let sequence = 0;
async function request(method, path, user, input, optional) {
  const id = `${profile}-${++sequence}`;
  const headers = {'Accept':'application/json','Authorization':`Bearer qualification-${user}`,'X-Request-Id':id};
  if (input) headers['Content-Type'] = 'application/json';
  if (optional) headers['X-Optional-Context'] = optional;
  const response = await fetch(base+path, {method,headers,body:input?JSON.stringify(input):undefined,signal:AbortSignal.timeout(30000)});
  const body = await response.text();
  const receipt = {id,method,path,user,status:response.status,headers:Object.fromEntries(response.headers),body};
  receipts.push(receipt);
  assert.equal(response.headers.get('x-request-id'),id);
  assert.equal(response.headers.get('x-qualification-user'),user);
  assert.equal(response.headers.get('x-qualification-namespace'),`qualification-${user}`);
  assert.equal(response.headers.get('x-optional-context'),optional??'');
  if (process.env.EXPECTED_CONNECTION) assert.equal(response.headers.get('x-db-connections'), process.env.EXPECTED_CONNECTION);
  return {...receipt,json:JSON.parse(body)};
}
async function status(instance,user) {
  const receipt=await request('GET',`/api/workflows/${instance}`,user);
  assert.equal(receipt.status,200);
  return receipt;
}
async function until(instance,user,expected) {
  const deadline=Date.now()+30000;
  let receipt;
  while(Date.now()<deadline) {
    receipt=await status(instance,user);
    if(receipt.json.status===expected) return receipt;
    assert.ok(!['failed','timed_out','terminated'].includes(receipt.json.status),receipt.body);
    await new Promise(resolve=>setTimeout(resolve,250));
  }
  throw new Error(`Unfinished ${instance}: ${receipt.body}`);
}
const cases=[];
try {
  for(const [user,mode] of [['alpha','timer'],['beta','signal'],['alpha','cancel'],['beta','continue'],['beta','fast'],['alpha','fast']]) {
    const instance=`${profile}-${user}-${mode}`;
    const start=await request('POST','/api/workflows',user,{instance_id:instance,mode},`${user}-only`);
    assert.equal(start.status,202,start.body);
    cases.push({instance,user,mode,original_run_id:start.json.run_id});
  }
  for(const test of cases) {
    const foreign=await request('GET',`/api/workflows/${test.instance}`,test.user==='alpha'?'beta':'alpha');
    assert.equal(foreign.status,404);
    if(test.mode==='signal'||test.mode==='cancel') {
      await until(test.instance,test.user,'waiting');
      const query=await request('GET',`/api/workflows/${test.instance}/query`,test.user);
      assert.equal(query.status,200,query.body);
      assert.deepEqual(query.json.snapshot,{owner:test.user,state:'signal'});
      const action=await request('POST',`/api/workflows/${test.instance}/${test.mode==='signal'?'signal':'cancel'}`,test.user,{});
      assert.equal(action.status,200,action.body);
      assert.equal(action.json.accepted,true);
    }
    const final=await until(test.instance,test.user,test.mode==='cancel'?'cancelled':'completed');
    if(test.mode==='continue') assert.notEqual(final.json.run_id,test.original_run_id);
    else assert.equal(final.json.run_id,test.original_run_id);
    if(test.mode!=='cancel') {
      assert.equal(final.json.output.owner,test.user);
      assert.equal(final.json.output.version,1);
      assert.ok(final.body.includes('"integer":9007199254740993'));
      if(test.mode==='continue') assert.equal(final.json.output.generation,1);
    }
    test.final_run_id=final.json.run_id;
    test.final_status=final.json.status;
  }
  const pids=[...new Set(receipts.map(r=>r.headers['x-worker-pid']))];
  if(process.env.EXPECT_ONE_PID==='true') assert.equal(pids.length,1);
  if(process.env.EXPECT_RECYCLED==='true') assert.ok(pids.length>1);
  await writeFile(`/evidence/${profile}.json`,JSON.stringify({pass:true,profile,cases,pids,receipts},null,2));
  console.log(JSON.stringify({pass:true,profile,cases,requests:receipts.length,pids}));
} catch(error) {
  await writeFile(`/evidence/${profile}.json`,JSON.stringify({pass:false,profile,error:String(error),cases,receipts},null,2));
  throw error;
}
