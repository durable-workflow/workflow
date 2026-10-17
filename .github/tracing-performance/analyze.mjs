import assert from 'node:assert/strict';
import {readdir, readFile, realpath} from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

const roles = {app: [1, 50000, 512], worker: [4, 100000, 384], mysql: [1, 100000, 2048],
    redis: [1, 10000, 128], collector: [1, 20000, 256], probe: [1, 20000, 256]};
const repetitions = ['1', '2', '3'];
const critical = 4.3026527299;

function counters(text) {
    return Object.fromEntries(text.trim().split('\n').map(line => {
        const [key, value] = line.trim().split(/\s+/);
        const parsed = Number(value);
        assert.ok(Number.isSafeInteger(parsed) && parsed >= 0, `Invalid ${key} counter`);
        return [key, parsed];
    }));
}

function snapshot(rows) {
    assert.equal(rows.length, 9, 'All nine roles must be readable');
    const counts = {};
    const result = new Map();
    for (const row of rows) {
        assert.ok(Object.hasOwn(roles, row.role), 'Unknown runtime role');
        const [, quota, memory] = roles[row.role];
        assert.match(row.container, /^[a-f0-9]{64}$/);
        assert.ok(!result.has(row.container), 'Duplicate container');
        assert.deepEqual(row.cpu_max.trim().split(/\s+/).map(Number), [quota, 100000]);
        assert.equal(Number(row.memory_max), memory * 1024 * 1024);
        assert.equal(Number(row.swap_max), 0);
        const memoryEvents = counters(row.memory_events);
        assert.equal(memoryEvents.oom, 0);
        assert.equal(memoryEvents.oom_kill, 0);
        const cpu = counters(row.cpu);
        assert.ok(Object.hasOwn(cpu, 'usage_usec') && Object.hasOwn(cpu, 'throttled_usec'));
        assert.ok(Number.isFinite(Date.parse(row.sampled_at)), 'Missing sample timestamp');
        assert.ok(Number.isSafeInteger(row.memory_current_bytes) && row.memory_current_bytes >= 0);
        assert.ok(Number.isSafeInteger(row.lifetime_memory_peak_bytes) && row.lifetime_memory_peak_bytes >= 0);
        result.set(row.container, {...row, counters: cpu});
        counts[row.role] = (counts[row.role] ?? 0) + 1;
    }
    for (const [role, [count]] of Object.entries(roles)) assert.equal(counts[role], count);
    return result;
}

function validateBusiness(data) {
    const {outcome, measure, warmup, audit} = data;
    assert.equal(outcome.result, 0, 'An unsuccessful original is not a CPU sample');
    assert.equal(outcome.stage, 'complete');
    assert.ok(['base', 'off', 'on'].includes(outcome.cell));
    assert.ok(['ordinary', 'fanout'].includes(outcome.workload));
    const ordinary = outcome.workload === 'ordinary';
    const expected = ordinary ? 120 : 2;
    const members = ordinary ? 1 : 64;
    for (const [probe, count] of [[measure, expected], [warmup, ordinary ? 30 : 1]]) {
        assert.equal(probe.offered_target, count);
        for (const key of ['offered', 'accepted', 'completed']) assert.equal(probe[key], count);
        assert.equal(probe.members, members);
        assert.equal(probe.observer_seconds, 90);
        assert.equal(probe.missed_offers, 0);
        assert.equal(probe.http_errors, 0);
        assert.deepEqual(probe.failures, []);
        assert.equal(probe.queue_wake_enabled, true);
        assert.equal(probe.queue_driver, 'database');
        assert.equal(probe.diagnostic_only, false);
    }
    assert.equal(measure.duration_seconds, 120);
    assert.equal(measure.rate, ordinary ? 1 : 1 / 60);
    const lastOfferSeconds = (expected - 1) / measure.rate;
    assert.ok(Number.isFinite(measure.elapsed_seconds) && measure.elapsed_seconds >= lastOfferSeconds);
    assert.ok([measure.p50_ms, measure.p95_ms, measure.p99_ms].every(value => Number.isFinite(value) && value > 0));
    assert.ok(measure.p50_ms <= measure.p95_ms && measure.p95_ms <= measure.p99_ms);
    const runs = expected + (ordinary ? 30 : 1);
    assert.equal(audit.runs, runs);
    assert.equal(audit.activities, runs * members);
    assert.equal(audit.callbacks, runs * members);
    assert.equal(audit.tasks, runs * (members + 2));
    assert.deepEqual(audit.history_types, {StartAccepted: runs, WorkflowStarted: runs,
        ActivityScheduled: runs * members, ActivityStarted: runs * members,
        ActivityCompleted: runs * members, WorkflowCompleted: runs});
    assert.ok(Number.isSafeInteger(audit.repairs) && audit.repairs >= 0);
    assert.deepEqual(new Set(Object.keys(audit.metadata_bytes)), new Set(['activity_executions', 'workflow_runs', 'workflow_tasks']));
    for (const bytes of Object.values(audit.metadata_bytes)) assert.ok(Number.isSafeInteger(bytes) && bytes >= 0);
    for (const key of ['unfinished_runs', 'unfinished_activities', 'unfinished_tasks', 'nonfirst_tasks',
        'unfinished_attempts', 'nonfirst_attempts', 'queue', 'failed_jobs']) assert.equal(audit[key], 0, key);
    return expected;
}

function validateTelemetry({outcome, audit, telemetry}) {
    assert.equal(telemetry.ended, telemetry.received, 'Span loss invalidates the cell');
    assert.ok(Number.isSafeInteger(telemetry.ended) && telemetry.ended >= 0);
    if (outcome.cell === 'on') {
        assert.ok(telemetry.ended > 0);
        assert.ok(audit.metadata_bytes.workflow_runs > 0 && audit.metadata_bytes.workflow_tasks > 0);
    } else {
        assert.equal(telemetry.ended, 0);
        for (const bytes of Object.values(audit.metadata_bytes)) assert.equal(bytes, 0);
    }
}

function summarizeComponents(data, before, after) {
    assert.deepEqual(new Set(before.keys()), new Set(after.keys()), 'Container replacement invalidates CPU');
    const samples = data.samples;
    assert.ok(samples.length >= 18 && samples.length % 9 === 0, 'Incomplete sampler output');
    const components = {};
    const observedIntervals = [];
    for (const [id, initial] of before) {
        const final = after.get(id);
        assert.equal(final.role, initial.role);
        const delta = final.counters.usage_usec - initial.counters.usage_usec;
        const throttle = final.counters.throttled_usec - initial.counters.throttled_usec;
        assert.ok(delta >= 0 && throttle >= 0, 'Decreasing CPU counter');
        const component = components[initial.role] ??= {cpu_seconds: 0, throttled_seconds: 0,
            sum_container_sampled_peaks_bytes: 0, sampled_memory_mean_bytes: 0, sum_container_lifetime_peaks_bytes: 0};
        component.cpu_seconds += delta / 1e6;
        component.throttled_seconds += throttle / 1e6;
        const observations = samples.filter(row => row.container === id);
        assert.equal(observations.length, samples.length / 9, 'Sampler lost a role');
        const values = observations.map(row => row.memory_current_bytes);
        assert.ok(values.every(value => Number.isSafeInteger(value) && value >= 0));
        component.sum_container_sampled_peaks_bytes += Math.max(...values);
        component.sampled_memory_mean_bytes += values.reduce((sum, value) => sum + value, 0) / values.length;
        component.sum_container_lifetime_peaks_bytes += final.lifetime_memory_peak_bytes;
        const times = observations.map(row => Date.parse(row.sampled_at));
        const intervals = times.slice(1).map((value, index) => value - times[index]);
        assert.ok(intervals.every(value => value > 0), 'Invalid sampling order');
        observedIntervals.push(...intervals);
        const intervalMs = Date.parse(final.sampled_at) - Date.parse(initial.sampled_at);
        assert.ok(intervalMs >= data.measure.duration_seconds * 1000, 'CPU interval must cover the offer window');
    }
    return {components, observedIntervals};
}

function validateExits(data, before) {
    assert.equal(data.workerExits.length, 4);
    assert.equal(data.probeExits.length, 2);
    for (const code of [...data.workerExits, ...data.probeExits]) assert.equal(code, 0);
    assert.equal(data.exits.length, 9);
    assert.deepEqual(new Set(data.exits.map(item => item.id)), new Set(before.keys()));
    for (const item of data.exits) {
        assert.equal(item.oom, false);
        assert.equal(item.restarts, 0);
        const role = before.get(item.id).role;
        assert.equal(item.exit, ['mysql', 'redis'].includes(role) ? 0 : 143);
    }
}

export function summarizeCell(data) {
    const expected = validateBusiness(data);
    validateTelemetry(data);
    const before = snapshot(data.before);
    const after = snapshot(data.after);
    validateExits(data, before);
    const {components, observedIntervals} = summarizeComponents(data, before, after);
    const {outcome, measure, audit, telemetry} = data;
    const cpuSeconds = Object.values(components).reduce((sum, item) => sum + item.cpu_seconds, 0);
    assert.ok(cpuSeconds > 0);
    return {...outcome, cpu_seconds: cpuSeconds, cpu_seconds_per_workflow: cpuSeconds / expected,
        php_cpu_seconds: components.app.cpu_seconds + components.worker.cpu_seconds, components,
        completed_per_offer_second: expected / 120, completed_per_observed_second: expected / measure.elapsed_seconds,
        measured: measure, audit_including_warmup: audit, spans_including_warmup: telemetry,
        sample_interval_ms: {min: Math.min(...observedIntervals), max: Math.max(...observedIntervals),
            mean: observedIntervals.reduce((sum, value) => sum + value, 0) / observedIntervals.length}};
}

function interval(values) {
    assert.equal(values.length, 3);
    const mean = values.reduce((sum, value) => sum + value, 0) / 3;
    const variance = values.reduce((sum, value) => sum + (value - mean) ** 2, 0) / 2;
    const margin = critical * Math.sqrt(variance / 3);
    return {mean, lower: mean - margin, upper: mean + margin};
}

export function compare(cells) {
    assert.equal(cells.length, 20, 'All twenty declared cells are required');
    const result = {};
    for (const workload of ['ordinary', 'fanout']) {
        const select = (cell, repeat) => {
            const matches = cells.filter(row => row.workload === workload && row.cell === cell && row.repeat === repeat);
            assert.equal(matches.length, 1, 'Missing or repeated matched cell');
            assert.ok(Number.isFinite(matches[0].cpu_seconds_per_workflow) && matches[0].cpu_seconds_per_workflow > 0);
            return matches[0].cpu_seconds_per_workflow;
        };
        const baseline = repetitions.map(repeat => select('base', repeat));
        const meanBase = baseline.reduce((sum, value) => sum + value, 0) / 3;
        const drift = select('base', 'drift') / meanBase - 1;
        result[workload] = {baseline_cpu_seconds_per_workflow: baseline, baseline_drift_ratio: drift};
        for (const [cell, target] of [['off', 0.01], ['on', 0.05]]) {
            const paired = repetitions.map((repeat, index) => select(cell, repeat) / baseline[index] - 1);
            const confidence = interval(paired);
            result[workload][cell] = {paired_ratios: paired, confidence_95: confidence, target,
                qualified: confidence.upper <= target && Math.abs(drift) <= target};
        }
    }
    return result;
}

function within(directory, resolved) {
    if (resolved !== directory && !resolved.startsWith(directory + path.sep)) {
        throw new RangeError('Experiment input escapes its selected directory');
    }
    return resolved;
}

export async function experimentRoot(input, directory = process.cwd()) {
    const allowed = await realpath(directory);
    return within(allowed, await realpath(path.resolve(allowed, input)));
}

export async function dataPath(root, file) {
    return within(root, await realpath(file));
}

const json = async (root, file) => JSON.parse(await readFile(await dataPath(root, file), 'utf8'));
const jsonLines = async (root, file) => (await readFile(await dataPath(root, file), 'utf8'))
    .trim().split('\n').map(line => JSON.parse(line));

export async function loadCell(root, name) {
    root = await experimentRoot(root);
    assert.match(name, /^tracing-measure-(base|off|on)-(ordinary|fanout)-(1|2|3|drift)$/);
    const proof = await dataPath(root, path.join(root, 'proof', name));
    const state = await dataPath(root, path.join(root, 'state', name));
    const exitCodes = async directory => {
        const files = (await readdir(await dataPath(root, directory))).filter(file => file.endsWith('.exit'));
        return Promise.all(files.map(async file => {
            const value = (await readFile(await dataPath(root, path.join(directory, file)), 'utf8')).trim();
            assert.match(value, /^(0|[1-9]\d*)$/, 'Missing or invalid child exit');
            return Number(value);
        }));
    };
    return summarizeCell({outcome: await json(root, path.join(proof, 'outcome.json')),
        measure: await json(root, path.join(proof, 'measure-result.json')), warmup: await json(root, path.join(proof, 'warmup-result.json')),
        audit: await json(root, path.join(proof, 'audit.json')), telemetry: await json(root, path.join(proof, 'telemetry.json')),
        before: await jsonLines(root, path.join(proof, 'before.jsonl')), after: await jsonLines(root, path.join(proof, 'after.jsonl')),
        samples: await jsonLines(root, path.join(proof, 'samples.jsonl')), exits: await jsonLines(root, path.join(proof, 'exits.jsonl')),
        workerExits: await exitCodes(path.join(state, 'runtime')), probeExits: await exitCodes(path.join(state, 'results'))});
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const input = process.argv[2];
    assert.ok(input, 'Usage: node analyze.mjs COPIED_EXPERIMENT_ROOT');
    const root = await experimentRoot(input);
    const names = (await readdir(await dataPath(root, path.join(root, 'proof'))))
        .filter(name => name.startsWith('tracing-measure-'));
    const cells = await Promise.all(names.map(name => loadCell(root, name)));
    console.log(JSON.stringify({method: 'paired-t-95-n3-df2; targets and drift frozen before admission',
        comparison: compare(cells), cells}, null, 2));
}
