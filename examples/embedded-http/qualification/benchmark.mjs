import assert from 'node:assert/strict';
import {performance} from 'node:perf_hooks';
import {writeFile} from 'node:fs/promises';

const base = process.env.BASE_URL;
const profile = process.env.PROFILE_NAME;
const rate = Number(process.env.OFFERED_RATE ?? 5);
const duration = Number(process.env.DURATION_SECONDS ?? 60);
const drain = Number(process.env.DRAIN_SECONDS ?? 30);
const mixed = process.env.MIXED === 'true';
assert.ok(base && /^[a-zA-Z0-9_-]{1,40}$/.test(profile));
assert.ok(rate > 0 && duration > 0 && drain >= 0);
const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const started = performance.now();
const startedAt = Date.now();
const hardEnd = started + (duration + drain) * 1000;
const requests = {admission: [], status: [], query: [], signal: []};
const outcomes = [];
const pids = new Set();
let queries = 0, historyQueries = 0, sequence = 0;

async function request(kind, method, path, user, input) {
    const begin = performance.now();
    const headers = {Accept: 'application/json', Authorization: `Bearer qualification-${user}`,
        'X-Request-Id': `${profile}-${++sequence}`};
    if (input) headers['Content-Type'] = 'application/json';
    const remaining = hardEnd - begin;
    if (remaining <= 0) throw new Error('observation_deadline');
    let response, text;
    try {
        response = await fetch(base + path, {method, headers, body: input ? JSON.stringify(input) : undefined,
            signal: AbortSignal.timeout(Math.max(1, Math.min(10000, Math.floor(remaining))))});
        text = await response.text();
    } catch (error) {
        requests[kind].push({ms: performance.now() - begin, status: response?.status ?? null,
            transport_error: String(error)});
        throw error;
    }
    requests[kind].push({ms: performance.now() - begin, status: response.status});
    pids.add(response.headers.get('x-worker-pid'));
    queries += Number(response.headers.get('x-db-queries') ?? 0);
    historyQueries += Number(response.headers.get('x-history-queries') ?? 0);
    assert.equal(response.headers.get('x-qualification-user'), user);
    assert.equal(response.headers.get('x-qualification-namespace'), `qualification-${user}`);
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${text.slice(0, 300)}`);
    return {json: JSON.parse(text), text};
}

async function workflow(index) {
    const begin = performance.now();
    const user = index % 2 ? 'beta' : 'alpha';
    const mode = mixed ? ['fast', 'timer', 'fast', 'signal', 'fast'][index % 5] : 'fast';
    const instance = `${profile}-${index}`;
    const outcome = {instance, mode, offered_at_ms: begin - started, accepted: false, completed: false};
    outcomes.push(outcome);
    try {
        const original = await request('admission', 'POST', '/api/workflows', user, {instance_id: instance, mode});
        outcome.accepted = true;
        outcome.run_id = original.json.run_id;
        let signalled = false;
        while (performance.now() < hardEnd) {
            const status = await request('status', 'GET', `/api/workflows/${instance}`, user);
            assert.equal(status.json.run_id, outcome.run_id);
            if (status.json.status === 'completed') {
                assert.equal(status.json.output.owner, user);
                assert.equal(status.json.output.version, 1);
                assert.ok(status.text.includes('"integer":9007199254740993'));
                assert.equal(status.json.output.activity.optional, null);
                assert.equal(status.json.output.activity.unicode, 'activity λ');
                outcome.completed = true;
                outcome.completed_at_ms = performance.now() - started;
                outcome.latency_ms = performance.now() - begin;
                return;
            }
            assert.ok(!['failed', 'cancelled', 'terminated', 'timed_out'].includes(status.json.status));
            if (mode === 'signal' && status.json.status === 'waiting' && !signalled) {
                await request('query', 'GET', `/api/workflows/${instance}/query`, user);
                const signal = await request('signal', 'POST', `/api/workflows/${instance}/signal`, user, {});
                assert.equal(signal.json.accepted, true);
                signalled = true;
            }
            await sleep(250);
        }
        throw new Error('observation_deadline');
    } catch (error) {
        outcome.error = String(error);
    }
}

const pending = [];
const offered = Math.floor(rate * duration);
let maximumScheduleLagMs = 0;
for (let index = 0; index < offered; index++) {
    const due = started + index * 1000 / rate;
    await sleep(Math.max(0, due - performance.now()));
    maximumScheduleLagMs = Math.max(maximumScheduleLagMs, performance.now() - due);
    pending.push(workflow(index));
}
const offerEnd = started + duration * 1000;
await sleep(Math.max(0, offerEnd - performance.now()));
const completedAtOfferEnd = outcomes.filter(outcome => outcome.completed).length;
await Promise.all(pending);
const finished = performance.now();
const distribution = values => {
    const sorted = [...values].sort((left, right) => left - right);
    const percentile = p => sorted[Math.max(0, Math.ceil(sorted.length * p) - 1)] ?? null;
    return {count: sorted.length, p50_ms: percentile(0.5), p95_ms: percentile(0.95), p99_ms: percentile(0.99)};
};
const completed = outcomes.filter(outcome => outcome.completed);
const result = {profile, rate, duration_seconds: duration, drain_budget_seconds: drain, mixed, offered,
    started_at_ms: startedAt, finished_at_ms: Date.now(),
    accepted: outcomes.filter(outcome => outcome.accepted).length, completed: completed.length,
    completed_at_offer_end: completedAtOfferEnd, unfinished: outcomes.filter(outcome => !outcome.completed).length,
    elapsed_seconds: (finished - started) / 1000, measured_drain_seconds: Math.max(0, (finished - offerEnd) / 1000),
    maximum_schedule_lag_ms: maximumScheduleLagMs, completed_per_second: completedAtOfferEnd / duration,
    workflow_latency: distribution(completed.map(outcome => outcome.latency_ms)),
    http: Object.fromEntries(Object.entries(requests).map(([kind, values]) => [kind,
        {...distribution(values.map(value => value.ms)), errors: values.filter(value => value.status >= 400).length,
            transport_errors: values.filter(value => value.transport_error).length}])),
    db_queries: queries, history_queries: historyQueries, pids: [...pids],
    errors: outcomes.filter(outcome => outcome.error), outcomes};
await writeFile(`/evidence/${profile}.json`, JSON.stringify(result));
console.log(JSON.stringify({...result, outcomes: undefined}));
if (result.unfinished) process.exitCode = 1;
