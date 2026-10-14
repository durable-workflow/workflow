import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';

const profile = process.env.PROFILE_NAME;
const base = process.env.BASE_URL;
const instance = `${profile}-cleanup`;
const receipts = [];
async function request(method, path, body) {
    const response = await fetch(base + path, {
        method,
        headers: {Accept: 'application/json', Authorization: 'Bearer qualification-alpha',
            'Content-Type': 'application/json', 'X-Request-Id': `${profile}-${receipts.length}`},
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(10000),
    });
    const text = await response.text();
    const receipt = {status: response.status, path, body: text, pid: response.headers.get('x-worker-pid')};
    receipts.push(receipt);
    assert.ok(response.ok, text);
    return JSON.parse(text);
}
try {
    const original = await request('POST', '/api/workflows', {instance_id: instance, mode: 'cleanup'});
    const path = `/api/workflows/${instance}`;
    const deadline = Date.now() + 30000;
    let status;
    do {
        status = await request('GET', path);
        assert.equal(status.run_id, original.run_id);
        if (status.status === 'waiting') break;
        assert.ok(!['failed', 'completed', 'cancelled'].includes(status.status));
        await new Promise(resolve => setTimeout(resolve, 200));
    } while (Date.now() < deadline);
    assert.equal(status.status, 'waiting');
    const first = await request('POST', `${path}/request-cancellation`, {});
    const duplicate = await request('POST', `${path}/request-cancellation`, {});
    assert.equal(first.accepted, true);
    assert.equal(duplicate.command_id, first.command_id);
    do {
        status = await request('GET', path);
        assert.equal(status.run_id, original.run_id);
        if (status.status === 'cancelled') break;
        assert.ok(!['failed', 'completed', 'terminated', 'timed_out'].includes(status.status));
        await new Promise(resolve => setTimeout(resolve, 200));
    } while (Date.now() < deadline);
    assert.equal(status.status, 'cancelled');
    assert.deepEqual(status.memo, {cleanup_owner: 'alpha', cleanup_completed: true});
    const result = {pass: true, profile, run_id: original.run_id, cancellation_command_id: first.command_id,
        pids: [...new Set(receipts.map(receipt => receipt.pid))], receipts};
    await writeFile(`/evidence/${profile}.json`, JSON.stringify(result));
    console.log(JSON.stringify({...result, receipts: receipts.length}));
} catch (error) {
    await writeFile(`/evidence/${profile}.json`, JSON.stringify({pass: false, error: String(error), receipts}));
    throw error;
}
