import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
const base=process.env.BASE_URL, profile=process.env.PROFILE_NAME, phase=process.env.PHASE;
const receipts=[];
let seq=0;
async function request(method,path,user,input){
  const id=`${profile}-${phase}-${++seq}`;
  const headers={Accept:'application/json',Authorization:`Bearer qualification-${user}`,'X-Request-Id':id};
  if(input)headers['Content-Type']='application/json';
  const r=await fetch(base+path,{method,headers,body:input?JSON.stringify(input):undefined,signal:AbortSignal.timeout(10000)});
  const body=await r.text(),receipt={id,method,path,user,status:r.status,headers:Object.fromEntries(r.headers),body};
  receipts.push(receipt);
  assert.equal(r.headers.get('x-request-id'),id);
  assert.equal(r.headers.get('x-qualification-user'),user);
  assert.equal(r.headers.get('x-qualification-namespace'),`qualification-${user}`);
  assert.equal(r.headers.get('x-optional-context'),'');
  return {...receipt,json:JSON.parse(body)};
}
let original;
try{
  if(phase==='prepare'){
    const instance=`${profile}-alpha-fault`;
    const start=await request('POST','/api/workflows','alpha',{instance_id:instance,mode:'fault'});
    assert.equal(start.status,202,start.body);
    original={instance,run_id:start.json.run_id};
    const deadline=Date.now()+20000;
    let ready=false;
    while(Date.now()<deadline){
      const status=await request('GET',`/api/workflows/${instance}`,'alpha');
      assert.equal(status.status,200,status.body);
      assert.equal(status.json.run_id,original.run_id);
      if(status.json.status==='waiting'){
        const query=await request('GET',`/api/workflows/${instance}/query`,'alpha');
        assert.equal(query.status,200,query.body);
        if(query.json.snapshot.state==='timer'){ready=true;break;}
      }
      assert.ok(!['failed','completed','cancelled'].includes(status.json.status),status.body);
      await new Promise(r=>setTimeout(r,200));
    }
    assert.equal(ready,true);
    const foreign=await request('GET',`/api/workflows/${instance}`,'beta');
    assert.equal(foreign.status,404);
  }else if(phase==='outage'){
    original=JSON.parse(await readFile(`/evidence/${profile}-prepare.json`,'utf8')).original;
    const id=`${profile}-outage`;
    const r=await fetch(base+`/api/workflows/${original.instance}`,{headers:{Accept:'application/json',Authorization:'Bearer qualification-alpha','X-Request-Id':id},signal:AbortSignal.timeout(10000)});
    const body=await r.text();
    receipts.push({id,method:'GET',path:`/api/workflows/${original.instance}`,user:'alpha',status:r.status,headers:Object.fromEntries(r.headers),body});
    assert.ok([500,502,503].includes(r.status),body);
    if(r.headers.get('x-request-id')!==null){
      assert.equal(r.headers.get('x-request-id'),id);
      assert.equal(r.headers.get('x-qualification-user'),'alpha');
      assert.equal(r.headers.get('x-qualification-namespace'),'qualification-alpha');
    }
  }else{
    original=JSON.parse(await readFile(`/evidence/${profile}-prepare.json`,'utf8')).original;
    const deadline=Date.now()+50000;
    let completed=false;
    while(Date.now()<deadline){
      const status=await request('GET',`/api/workflows/${original.instance}`,'alpha');
      assert.equal(status.status,200,status.body);
      assert.equal(status.json.run_id,original.run_id);
      if(status.json.status==='completed'){
        assert.equal(status.json.output.owner,'alpha');
        assert.ok(status.body.includes('"integer":9007199254740993'));
        assert.equal(status.json.output.activity.unicode,'activity λ');
        completed=true;break;
      }
      assert.ok(!['failed','cancelled','timed_out','terminated'].includes(status.json.status),status.body);
      await new Promise(r=>setTimeout(r,500));
    }
    assert.equal(completed,true);
  }
  const result={pass:true,profile,phase,original,pids:[...new Set(receipts.map(r=>r.headers['x-worker-pid']))],receipts};
  await writeFile(`/evidence/${profile}-${phase}.json`,JSON.stringify(result,null,2));
  console.log(JSON.stringify({...result,receipts:receipts.length}));
}catch(error){
  await writeFile(`/evidence/${profile}-${phase}.json`,JSON.stringify({pass:false,profile,phase,original,error:String(error),receipts},null,2));
  throw error;
}
