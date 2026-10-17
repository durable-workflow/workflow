import assert from 'node:assert/strict';
import {mkdir, mkdtemp, rm, symlink, writeFile} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import {compare, dataPath, experimentRoot, summarizeCell} from './analyze.mjs';

function pairData() {
    const cells = [];
    for (const workload of ['ordinary', 'fanout']) {
        for (const repeat of ['1', '2', '3']) {
            for (const [cell, ratio] of [['base', 1], ['off', 1.002], ['on', 1.02]]) {
                cells.push({workload, repeat, cell, cpu_seconds_per_workflow: 10 * ratio});
            }
        }
        cells.push({workload, repeat: 'drift', cell: 'base', cpu_seconds_per_workflow: 10});
    }
    return cells;
}

function cellData() {
    const roles = [['app', 50000, 512], ...Array.from({length: 4}, () => ['worker', 100000, 384]),
        ['mysql', 100000, 2048], ['redis', 10000, 128], ['collector', 20000, 256], ['probe', 20000, 256]];
    const rows = (seconds, usage) => roles.map(([role, quota, memory], index) => ({
        container: (index + 1).toString(16).padStart(64, '0'), role,
        sampled_at: new Date(Date.UTC(2026, 0, 1, 0, 0, seconds)).toISOString(),
        cpu: `usage_usec ${usage}\nthrottled_usec 0\n`, cpu_max: `${quota} 100000\n`,
        memory_events: 'oom 0\noom_kill 0\n', memory_max: String(memory * 1024 * 1024), swap_max: '0',
        memory_current_bytes: 1024, lifetime_memory_peak_bytes: 2048,
    }));
    const measure = {members: 1, offered_target: 120, offered: 120, accepted: 120, completed: 120,
        observer_seconds: 90, missed_offers: 0, http_errors: 0, failures: [], queue_wake_enabled: true,
        queue_driver: 'database', diagnostic_only: false, duration_seconds: 120, rate: 1,
        elapsed_seconds: 120, p50_ms: 500, p95_ms: 750, p99_ms: 900};
    return {outcome: {result: 0, stage: 'complete', cell: 'base', workload: 'ordinary', repeat: '1'},
        measure, warmup: {...measure, offered_target: 30, offered: 30, accepted: 30, completed: 30},
        audit: {runs: 150, activities: 150, callbacks: 150, tasks: 450, unfinished_runs: 0,
            unfinished_activities: 0, unfinished_tasks: 0, nonfirst_tasks: 0, unfinished_attempts: 0,
            nonfirst_attempts: 0, queue: 0, failed_jobs: 0, repairs: 0,
            history_types: {StartAccepted: 150, WorkflowStarted: 150, ActivityScheduled: 150,
                ActivityStarted: 150, ActivityCompleted: 150, WorkflowCompleted: 150},
            metadata_bytes: {workflow_runs: 0, workflow_tasks: 0, activity_executions: 0}},
        telemetry: {ended: 0, received: 0}, workerExits: [0, 0, 0, 0], probeExits: [0, 0],
        before: rows(0, 1000), after: rows(120, 101000), samples: [...rows(1, 2000), ...rows(2, 3000)],
        exits: roles.map(([role], index) => ({id: (index + 1).toString(16).padStart(64, '0'),
            exit: ['mysql', 'redis'].includes(role) ? 0 : 143, oom: false, restarts: 0}))};
}

test('normalizes all nine CPU deltas by measured work, excluding warmup counts', () => {
    const result = summarizeCell(cellData());
    assert.ok(Math.abs(result.cpu_seconds - 0.9) < 1e-12);
    assert.ok(Math.abs(result.cpu_seconds_per_workflow - 0.0075) < 1e-12);
    assert.ok(Math.abs(result.php_cpu_seconds - 0.5) < 1e-12);
    assert.equal(result.sample_interval_ms.mean, 1000);
});

test('integer timer precision keeps the actual elapsed value without shortening CPU coverage', () => {
    const data = cellData();
    data.measure.elapsed_seconds = 119.999219339;
    assert.equal(summarizeCell(data).measured.elapsed_seconds, 119.999219339);
});

test('every cgroup interval must cover the declared offer window', () => {
    const data = cellData();
    data.after[0].sampled_at = '2026-01-01T00:01:59.999Z';
    assert.throws(() => summarizeCell(data), /CPU interval/);
});

test('the observation cannot finish before the last declared offer', () => {
    const data = cellData();
    data.measure.elapsed_seconds = 118.999;
    assert.throws(() => summarizeCell(data));
});

for (const [name, corrupt] of [
    ['failed original', data => { data.outcome.result = 1; }],
    ['lost span', data => { data.outcome.cell = 'on'; data.telemetry = {ended: 10, received: 9}; }],
    ['replaced container', data => { data.after[0].container = 'f'.repeat(64); }],
    ['decreasing CPU', data => { data.after[0].cpu = 'usage_usec 999\nthrottled_usec 0\n'; }],
    ['missing sample role', data => { data.samples.pop(); }],
    ['unexpected worker exit', data => { data.workerExits[0] = 137; }],
    ['unfinished business work', data => { data.audit.unfinished_tasks = 1; }],
    ['resource-limit drift', data => { data.after[0].cpu_max = '100000 100000'; }],
]) {
    test(`refuses ${name} as a qualified CPU sample`, () => {
        const data = cellData();
        corrupt(data);
        assert.throws(() => summarizeCell(data));
    });
}

test('uses paired relative overhead and the frozen confidence targets', () => {
    const result = compare(pairData());
    for (const workload of ['ordinary', 'fanout']) {
        assert.ok(Math.abs(result[workload].off.confidence_95.mean - 0.002) < 1e-12);
        assert.ok(Math.abs(result[workload].on.confidence_95.mean - 0.02) < 1e-12);
        assert.equal(result[workload].off.qualified, true);
        assert.equal(result[workload].on.qualified, true);
    }
});

test('a low mean with a wide interval does not qualify enabled tracing', () => {
    const cells = pairData();
    for (const [index, repeat] of ['1', '2', '3'].entries()) {
        cells.find(row => row.workload === 'ordinary' && row.cell === 'on' && row.repeat === repeat)
            .cpu_seconds_per_workflow = [10, 10.3, 9.7][index];
    }
    const result = compare(cells).ordinary.on;
    assert.ok(Math.abs(result.confidence_95.mean) < 1e-12);
    assert.ok(result.confidence_95.upper > 0.05);
    assert.equal(result.qualified, false);
});

test('baseline drift can refuse the tighter target while the enabled target passes', () => {
    const cells = pairData();
    cells.find(row => row.workload === 'ordinary' && row.repeat === 'drift').cpu_seconds_per_workflow = 10.15;
    const result = compare(cells).ordinary;
    assert.equal(result.off.qualified, false);
    assert.equal(result.on.qualified, true);
});

test('missing or duplicate cells cannot be silently selected out', () => {
    assert.throws(() => compare(pairData().slice(1)), /twenty/);
    const cells = pairData();
    cells[0] = {...cells[1]};
    assert.throws(() => compare(cells), /Missing or repeated/);
});

test('canonical input selection refuses parent, sibling-prefix and linked escapes', async t => {
    const workspace = await mkdtemp(path.join(process.cwd(), '.analysis-input-'));
    const sibling = workspace + '-sibling';
    await mkdir(sibling);
    t.after(async () => {
        await rm(workspace, {recursive: true});
        await rm(sibling, {recursive: true});
    });
    assert.equal(await experimentRoot('.', workspace), workspace);
    await assert.rejects(experimentRoot('..', workspace), /escapes/);
    await assert.rejects(experimentRoot(sibling, workspace), /escapes/);
    await symlink(sibling, path.join(workspace, 'linked-root'));
    await assert.rejects(experimentRoot('linked-root', workspace), /escapes/);
});

test('a copied result cannot link a data file outside its selected experiment', async t => {
    const workspace = await mkdtemp(path.join(process.cwd(), '.analysis-data-'));
    t.after(async () => { await rm(workspace, {recursive: true}); });
    const root = path.join(workspace, 'experiment');
    await mkdir(root);
    const normal = path.join(root, 'result.json');
    const outside = path.join(workspace, 'outside.json');
    await writeFile(normal, '{}');
    await writeFile(outside, '{}');
    assert.equal(await dataPath(root, normal), normal);
    const link = path.join(root, 'linked.json');
    await symlink(outside, link);
    await assert.rejects(dataPath(root, link), /escapes/);
});
