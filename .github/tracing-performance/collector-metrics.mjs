import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';

export const COLLECTOR_VERSION = '0.163.1';
export const COLLECTOR_IMAGE = 'otel/opentelemetry-collector:0.163.1@sha256:f0e23036dce3db3f9cb80d907c75a0d034cd32e93511a1943482d46129b9ae9c';
const maximumBytes = 256 * 1024;
const metricFields = new Map([
    ['otelcol_process_uptime', 'uptime_seconds'],
    ['otelcol_receiver_accepted_spans', 'accepted'],
    ['otelcol_receiver_refused_spans', 'refused'],
    ['otelcol_receiver_failed_spans', 'receiver_failed'],
    ['otelcol_exporter_sent_spans', 'sent'],
    ['otelcol_exporter_send_failed_spans', 'failed'],
    ['otelcol_exporter_in_flight_requests', 'in_flight'],
]);

function labels(value) {
    const result = Object.create(null);
    const pattern = /([a-zA-Z_][a-zA-Z0-9_]*)=("(?:[^"\\]|\\["\\n])*")(?:,|$)/gy;
    let offset = 0;
    for (const match of value.matchAll(pattern)) {
        assert.equal(match.index, offset, 'Malformed metric labels');
        assert.ok(!Object.hasOwn(result, match[1]), 'Duplicate metric label');
        result[match[1]] = JSON.parse(match[2]);
        offset = match.index + match[0].length;
    }
    assert.equal(offset, value.length, 'Incomplete metric labels');
    return result;
}

function metric(line) {
    const match = /^(otelcol_[a-z_]+)\{(.*)\} ([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)$/.exec(line);
    assert.ok(match, 'Malformed Collector counter sample');
    const attributes = labels(match[2]);
    if (match[1].startsWith('otelcol_receiver_')) {
        assert.equal(attributes.receiver, 'otlp', 'Unexpected receiver');
        assert.equal(attributes.transport, 'http', 'Unexpected receiver transport');
    }
    if (match[1].startsWith('otelcol_exporter_')) {
        assert.equal(attributes.exporter, 'debug', 'Unexpected exporter');
        if (Object.hasOwn(attributes, 'data_type')) assert.equal(attributes.data_type, 'traces');
    }
    return {field: metricFields.get(match[1]), attributes, value: Number(match[3])};
}

function validateSnapshot(snapshot) {
    assert.equal(snapshot.schema, 'durable-workflow.collector-snapshot-v1');
    assert.equal(snapshot.version, COLLECTOR_VERSION, 'Unexpected Collector version');
    assert.match(snapshot.instance, /^[a-zA-Z0-9._-]{1,128}$/, 'Missing Collector instance');
    assert.ok(Number.isFinite(snapshot.uptime_seconds) && snapshot.uptime_seconds > 0, 'Invalid Collector uptime');
    for (const field of ['accepted', 'sent', 'refused', 'receiver_failed', 'in_flight']) {
        assert.ok(Number.isSafeInteger(snapshot[field]) && snapshot[field] >= 0, `Missing/invalid ${field} counter`);
    }
    assert.ok(snapshot.accepted >= 1, 'Initialize the counter series with the declared synthetic probe');
    assert.equal(snapshot.accepted, snapshot.sent, 'Collector export has not drained');
    assert.equal(snapshot.in_flight, 0, 'Collector export is still in flight');
    assert.ok(snapshot.failed === null || (Number.isSafeInteger(snapshot.failed) && snapshot.failed >= 0),
        'Invalid exporter failure counter');
}

export function parseCollectorMetrics(text) {
    assert.equal(typeof text, 'string');
    assert.ok(Buffer.byteLength(text) <= maximumBytes, 'Collector metrics exceed the bounded response size');
    const snapshot = {schema: 'durable-workflow.collector-snapshot-v1', failed: null};
    const seen = new Set();
    for (const line of text.split('\n')) {
        const name = line.split(/[ {]/, 1)[0];
        if (!metricFields.has(name)) continue;
        const sample = metric(line);
        assert.ok(!seen.has(sample.field), 'Duplicate Collector counter series');
        seen.add(sample.field);
        const {service_instance_id: instance, service_version: version} = sample.attributes;
        snapshot.instance ??= instance;
        snapshot.version ??= version;
        assert.equal(instance, snapshot.instance, 'Mixed Collector instances');
        assert.equal(version, snapshot.version, 'Mixed Collector versions');
        snapshot[sample.field] = sample.value;
    }
    validateSnapshot(snapshot);
    return snapshot;
}

export function collectorDelta(proof) {
    assert.ok(proof && proof.before && proof.after, 'Missing Collector proof');
    const {before, after} = proof;
    validateSnapshot(before);
    validateSnapshot(after);
    assert.equal(before.instance, after.instance, 'Collector instance changed');
    assert.ok(after.uptime_seconds > before.uptime_seconds, 'Collector uptime did not advance');
    assert.ok(after.accepted >= before.accepted && after.sent >= before.sent, 'Collector counter reset');
    for (const field of ['refused', 'receiver_failed', 'failed']) {
        assert.equal(after[field], before[field], `Collector ${field} observation changed`);
    }
    return after.accepted - before.accepted;
}

export async function readCollectorSnapshot(fetcher = fetch) {
    const response = await fetcher('http://collector:8888/metrics', {signal: AbortSignal.timeout(2000)});
    assert.equal(response.status, 200, 'Collector metrics HTTP refusal');
    assert.ok(response.body, 'Missing Collector metrics body');
    const chunks = [];
    let bytes = 0;
    for await (const chunk of response.body) {
        bytes += chunk.byteLength;
        assert.ok(bytes <= maximumBytes, 'Collector metrics exceed the bounded response size');
        chunks.push(chunk);
    }
    return parseCollectorMetrics(new TextDecoder('utf-8', {fatal: true}).decode(Buffer.concat(chunks)));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    assert.equal(process.argv.length, 2, 'This command reads the fixed isolated Collector endpoint');
    console.log(JSON.stringify(await readCollectorSnapshot()));
}
