import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {createServer} from 'node:http';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

const execute = promisify(execFile);
const probe = fileURLToPath(new URL('./probe.mjs', import.meta.url));

async function refusesBeforeAdmission(t, health, diagnostic, message) {
    let submissions = 0;
    const server = createServer((request, response) => {
        if (request.method === 'POST') submissions++;
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({queue_driver: 'database', ...health}));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    t.after(async () => {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    });
    await assert.rejects(execute(process.execPath, [probe], {
        timeout: 5000,
        env: {...process.env, BASE_URL: `http://127.0.0.1:${server.address().port}`,
            PROFILE_NAME: 'quality-refusal', MEMBERS: '1', OFFERED_RATE: '1',
            DURATION_SECONDS: '1', DIAGNOSTIC_ONLY: String(diagnostic), BENCH_QUEUE_DRIVER: 'database'},
    }), error => {
        assert.equal(error.code, 1);
        assert.match(error.stderr, message);
        return true;
    });
    assert.equal(submissions, 0);
}

for (const [name, metadata] of [
    ['explicitly disabled', {ready: true, queue_wake_enabled: false}],
    ['missing', {ready: true}],
    ['nonboolean', {ready: true, queue_wake_enabled: 'true'}],
]) {
    test(`standard profile refuses ${name} queue-wake evidence`, async t => {
        await refusesBeforeAdmission(t, metadata, false, /Standard profile requires automatic queue wake/);
    });
}

test('diagnostic opt-in requires a diagnostic profile label', async t => {
    await refusesBeforeAdmission(t, {ready: true, queue_wake_enabled: false}, true, /diagnostic-/);
});

test('standard profile refuses another queue driver before admission', async t => {
    await refusesBeforeAdmission(t, {ready: true, queue_wake_enabled: true, queue_driver: 'redis'},
        false, /Expected queue driver/);
});
