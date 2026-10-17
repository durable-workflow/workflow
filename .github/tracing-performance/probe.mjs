import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {writeFile} from 'node:fs/promises';
import {performance} from 'node:perf_hooks';

const base = process.env.BASE_URL ?? 'http://app:8080';
const profile = process.env.PROFILE_NAME;
const members = Number(process.env.MEMBERS ?? 1);
const workload = process.env.WORKLOAD_NAME || (members === 1 ? 'ordinary' : 'fanout');
const rate = Number(process.env.OFFERED_RATE ?? (members === 1 ? 5 : 0.25));
const duration = Number(process.env.DURATION_SECONDS ?? 120);
const observation = 90_000;
const diagnostic = process.env.DIAGNOSTIC_ONLY === 'true';
const queueDriver = process.env.BENCH_QUEUE_DRIVER ?? 'database';
const allowedLagMs = 100;
assert.match(profile ?? '', /^[a-z0-9-]{1,55}$/);
assert.match(workload, /^[a-z0-9-]{1,55}$/);
assert.ok([1, 64].includes(members) && rate > 0 && duration > 0);
assert.ok(['database', 'redis'].includes(queueDriver));
const health = await fetch(base + '/health', {signal: AbortSignal.timeout(10_000)});
assert.equal(health.status, 200);
const deployment = await health.json();
assert.equal(deployment.ready, true);
assert.equal(deployment.queue_driver, queueDriver, 'Expected queue driver.');
const queueWakeEnabled = deployment.queue_wake_enabled === true;
if (diagnostic) {
    assert.equal(typeof deployment.queue_wake_enabled, 'boolean', 'Expected boolean queue-wake evidence.');
    assert.ok(profile.startsWith('diagnostic-'));
} else {
    assert.equal(queueWakeEnabled, true, 'Standard profile requires automatic queue wake.');
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const began = performance.now();
const beganAt = Date.now();
const outcomes = [];
const requestTimes = [];
let maximumLagMs = 0;
let missedOffers = 0;

async function request(path, deadline, input, traceparent) {
    const begin = performance.now();
    const remaining = deadline - begin;
    assert.ok(remaining > 0, 'Original workflow observer expired before request.');
    const response = await fetch(base + path, {
        method: input ? 'POST' : 'GET',
        headers: {'Content-Type': 'application/json', traceparent},
        body: input ? JSON.stringify(input) : undefined,
        signal: AbortSignal.timeout(Math.max(1, Math.floor(Math.min(10_000, remaining)))),
    });
    const text = await response.text();
    requestTimes.push({ms: performance.now() - begin, status: response.status});
    assert.equal(response.status, 200, `HTTP ${response.status}: ${text.slice(0, 300)}`);
    return {data: JSON.parse(text), text};
}

async function original(index) {
    const id = `${workload}-${index}`;
    const start = performance.now();
    const deadline = start + observation;
    const hash = createHash('sha256').update(id).digest('hex');
    const traceparent = `00-${hash.slice(0, 32)}-${hash.slice(32, 48)}-01`;
    const outcome = {id, completed: false};
    outcomes.push(outcome);
    try {
        const {data: admitted} = await request('/workflows', deadline, {id, members}, traceparent);
        assert.equal(admitted.id, id);
        assert.ok(admitted.run_id && admitted.execution_deadline && admitted.run_deadline);
        outcome.run_id = admitted.run_id;
        while (performance.now() < deadline) {
            const {data: current, text} = await request(`/workflows/${id}`, deadline, undefined, traceparent);
            for (const field of ['id', 'run_id', 'execution_deadline', 'run_deadline']) {
                assert.equal(current[field], admitted[field]);
            }
            if (current.status === 'completed') {
                assert.equal(current.output.length, members);
                for (let member = 0; member < members; member++) {
                    assert.deepEqual(Object.keys(current.output[member]), ['member', 'value']);
                    assert.equal(current.output[member].member, member);
                    assert.equal(current.output[member].value.length, 3);
                    assert.equal(typeof current.output[member].value[0], 'number');
                    assert.equal(current.output[member].value[1], null);
                    assert.equal(current.output[member].value[2], '雪');
                }
                assert.equal(text.match(/9007199254740993/g)?.length, members);
                outcome.completed = true;
                outcome.latency_ms = performance.now() - start;
                return;
            }
            assert.ok(['pending', 'running', 'waiting'].includes(current.status), `Unexpected ${current.status}`);
            await sleep(250);
        }
        throw new Error('Original 90-second workflow observer expired.');
    } catch (error) {
        outcome.error = String(error).slice(0, 500);
    }
}

const pending = [];
const offeredTarget = Math.floor(rate * duration);
assert.ok(offeredTarget > 0);
for (let index = 0; index < offeredTarget; index++) {
    const due = began + index * 1000 / rate;
    await sleep(Math.max(0, due - performance.now()));
    maximumLagMs = Math.max(maximumLagMs, performance.now() - due);
    if (performance.now() - due > allowedLagMs || performance.now() >= began + duration * 1000) {
        ++missedOffers;
        continue;
    }
    pending.push(original(index));
}
await sleep(Math.max(0, began + duration * 1000 - performance.now()));
const completedAtOfferEnd = outcomes.filter(item => item.completed).length;
await Promise.all(pending);
const completed = outcomes.filter(item => item.completed);
const sorted = completed.map(item => item.latency_ms).sort((a, b) => a - b);
const percentile = p => sorted[Math.max(0, Math.ceil(sorted.length * p) - 1)] ?? null;
const result = {
    profile, workload, members, rate, offered_target: offeredTarget, offered: pending.length, missed_offers: missedOffers,
    diagnostic_only: diagnostic, queue_wake_enabled: queueWakeEnabled,
    queue_driver: queueDriver,
    accepted: outcomes.filter(item => item.run_id).length,
    completed: completed.length, completed_at_offer_end: completedAtOfferEnd,
    started_at_ms: beganAt, finished_at_ms: Date.now(),
    duration_seconds: duration, observer_seconds: observation / 1000,
    elapsed_seconds: (performance.now() - began) / 1000, maximum_schedule_lag_ms: maximumLagMs,
    p50_ms: percentile(0.5), p95_ms: percentile(0.95), p99_ms: percentile(0.99),
    http_requests: requestTimes.length, http_errors: requestTimes.filter(item => item.status !== 200).length,
    process_usage: process.resourceUsage(), failures: outcomes.filter(item => !item.completed),
};
await writeFile(`/results/${profile}.json`, JSON.stringify(result));
console.log(JSON.stringify(result));
assert.equal(result.missed_offers, 0);
assert.equal(result.offered, offeredTarget);
assert.equal(result.accepted, offeredTarget);
assert.equal(result.completed, offeredTarget);
assert.equal(result.http_errors, 0);
