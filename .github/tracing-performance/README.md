# Embedded tracing overhead workload

This is the qualification recipe for draft Workflow #788. The
[October 17 measurement](RESULTS-2026-10-17.md) completed, but did not qualify
any of the four CPU budgets. The current prospective recipe uses the stable
OpenTelemetry API baggage fix. The remaining acceptance requirements of
[#773](https://github.com/durable-workflow/workflow/issues/773) are separate
release gates.

## Prospective official Collector profile

`compose.collector.yaml` selects a separately labelled official Collector profile.
It pins Collector 0.163.1 to its verified image digest and retains the same nine
roles, CPU/memory/swap limits and 1%/5% acceptance targets. The default PHP receiver
and October 17 report remain their original method. Do not transfer or combine
measurements across these collector profiles. The new protocol/counter controls
do not qualify a complete application cohort, overhead budget or tracing release.

The Collector decodes official OTLP protobuf and uses a basic debug exporter with
logging suppressed. It retains no span payloads. Its
[internal metrics](https://opentelemetry.io/docs/collector/internal-telemetry/)
provide accepted, refused, receiver-failed and exported span counters, plus
in-flight request counts.
The exporter-failure series can be absent before a failure; the reader preserves
that absence as `null` and refuses changed failure observations. It does not
report missing counters as zero or infer payload equality from delivery counts.

Before warmup, send exactly one synthetic calibration span using
`collector-probe.php` with its default count. This bounded setup request creates
the required counter series. The helper uses the normal official PHP SDK/exporter
and never writes the application's `ended.jsonl`. Its one-second transport timeout
is setup-only; business exports retain their existing 100 ms timeout. Read a
drained initial snapshot after calibration, then retain the Collector through
warmup, measured work and complete application/provider drain. Subtract only the
initial snapshot. Application-ended spans, including warmup, must equal both the
accepted and exported deltas. Disabled cells can prove a real zero delta on these
initialized counters. An uninitialized/missing series refuses observation.

Use the override explicitly for every command in this profile. For example, these
commands exercise only calibration and metrics, without starting database or
application roles:

```sh
docker compose -f compose.yaml -f compose.collector.yaml up -d collector
docker compose -f compose.yaml -f compose.collector.yaml run --rm --no-deps prepare \
  php collector-probe.php
docker compose -f compose.yaml -f compose.collector.yaml run --rm --no-deps probe \
  node collector-metrics.mjs
```

The snapshot command reads only the fixed internal `collector:8888/metrics`
endpoint, with a two-second timeout and 256 KiB response bound. During a measured
cell, execute it within the existing probe container before the final CPU snapshot
so its work remains in the nine-role accounting. Do not create an uncounted
measurement-side helper. No public port or production collector is needed.

Declare `outcome.collector` as `otelcol` and `outcome.collector_image` as the exact
digest-qualified override image. Store `telemetry.collector.before` and `.after`
snapshots alongside the existing `ended` and `received` counts. The analyzer
independently verifies those counts, version, instance, monotonic counters/uptime,
drained exports and unchanged failure observations. Missing proof, replacement,
reset, loss or a mixed-profile comparison refuses qualification. Historical
PHP-collector records lack these fields and remain explicitly the legacy method.
The official Collector must exit zero on SIGTERM. A forced 143 exit is not
graceful cleanup for this profile; the historical PHP receiver keeps its original
shutdown expectation.
The configuration preserves the Collector's process instance identifier and adds
a separate Docker-hostname label. The analyzer requires that container label to
match the measured CPU role's container-ID prefix. A same-container process restart
still changes the process identifier and refuses the old counter proof.

The prospective package baseline is published Workflow 2.7.3 with OpenTelemetry
API 1.10.1. Both baseline
and candidate include the released bounded history-payload read optimization.
This source/baseline update precedes admission of any official-Collector
measurement. The earlier prospective 2.6.1, 2.6.2 and 2.7.0 preparations and the October 17
study's 2.5.28 lock remain available at their recorded sources. Their results
are not measurements of this new source/baseline/Collector combination.

## Fixed comparison

The primary profile uses Laravel's database queue on MySQL and shared Redis
cache. This follows the reported embedded database-queue path. The earlier
Redis-transport prototypes remain unsuccessful observations, with no overhead
acceptance transferred from their diagnostic controls. A separate fresh stack
can select `BENCH_QUEUE_DRIVER=redis`; the probe verifies the actual driver
before admission, and its results must remain labeled separately.

Use three cells with the same application, PHP/dependency lock, database,
collector, logging call, worker count and resource limits:

1. The published Workflow version in `composer.lock`, with tracing disabled.
2. The exact candidate source, with tracing disabled.
3. That same candidate, with tracing enabled and an accepting local collector.

Record the published package reference, candidate commit/tree, loaded source
hashes, complete dependency references and resolved image digests before any
measurement. Only the Workflow package may differ between installations.
This prospective baseline includes the released MySQL admission correction;
it does not supply retrospective measurements of earlier implementations.
Its exact dependency constraints are deliberate experiment inputs. CI validates
the manifest and lock with Composer's strict validation, excluding generic
warnings about overly strict version constraints and package publication.

The previously declared acceptance targets remain at most **1% additional CPU
per completed workflow when disabled** and **5% when enabled**. Measure at least
three independent matched repetitions and repeat the published baseline to
check drift. If noise cannot resolve a target, its acceptance remains unproven.
Do not infer overhead from functional test duration or compare old measurements
with a changed machine or workload.

Run on a separate uncontended host with at least eight logical CPUs and 16 GiB
RAM, swap disabled. Record actual CPU model, kernel, virtualization/steal time,
Docker version and host activity. Builds and dependency installation finish
before measurement.

## Workloads and bounds

Run these independently with fresh databases and application directories:

The offered rates below are prospective inputs. Passing one-workflow controls
does not establish that either runtime sustains these rates. Establish the
published baseline's capacity before admitting matched measurement windows;
record any revised offered rate before starting new windows.

`PROFILE_NAME` labels results. `WORKLOAD_NAME` supplies the business identifier
prefix and trace-parent seed, so keep it identical across matched cells. Its
defaults are `ordinary` and `fanout`. Give warmup a separate `warmup-*` workload
name to keep its original runs distinct from the measured offers.

| Workload | Members per workflow | Offered workflows/second | Offer window |
| --- | ---: | ---: | ---: |
| Ordinary activity | 1 | 1 | 120 seconds |
| Large fan-out | 64 | 1/60 | 120 seconds |

Defaults live in `workloads.json`. These rates are chosen prospectively after
the guest functional controls, before matched overhead windows. They seek a
healthy comparison, retaining all 64 concurrent member activities and the
original observers. They establish no maximum-capacity or throughput claim.
The earlier prospective 5/s and 0.25/s inputs were not qualified and are not
reinterpreted as these new profiles. Verify the new rates with the published
baseline before using them for the three-cell comparison.

Each member performs one durable database insertion, one Laravel log call and
returns the exact int64/null/Unicode result. A unique activity ID and run/member
pair refuse duplicate callbacks. Every original keeps its initial 90-second
observer, 100-second run deadline and 120-second execution deadline. Offer
scheduling, transport refusals and unfinished work count as failures. A slow
offer generator is recorded, never relabeled as offered work.
Offers more than 100 ms late are missed offers; the probe does not catch up by
submitting them after their declared offer time.

First complete functional one-workflow controls for both member counts in each
cell. Then warm each fresh measured cell for 30 seconds without counting that
warmup as measured work. Complete and audit the warmup before releasing measured
offers. Keep sampling through the complete observation drain,
worker shutdown and pending export completion. Do not add a repair pass to make
a failed cell complete.

`compose.yaml` allocates HTTP 0.5 CPU/512 MiB, four independent workers at
1 CPU/384 MiB each, MySQL 1 CPU/2 GiB, Redis 0.1 CPU/128 MiB, collector
0.2 CPU/256 MiB and probe 0.2 CPU/256 MiB. Every role has no swap. These are
qualification inputs, not production sizing advice.

All three cells install the same application-owned OpenTelemetry provider.
The optional Laravel `LogContext` tap is registered only in the enabled cell,
using the same flag as tracing. Disabled CPU measurements cover that conditional
configuration. The [source integration guide](../../docs/embedded-tracing.md)
shows the corresponding application setup.
Workers share Redis cache on a separate logical database from their queue.
The runtime's repair throttle therefore coordinates across all four workers;
a process-local array cache is not this multi-process profile.
Automatic queue wake stays enabled in every standard cell. A separately named
`diagnostic-*` control can set `BENCH_QUEUE_WAKE=false` together with
`DIAGNOSTIC_ONLY=true` to isolate background matching. Its response records the
actual setting and diagnostic status. Diagnostic results remain outside
overhead acceptance and do not qualify timer or recovery behavior. The normal
probe refuses a deployment with automatic wake disabled before admitting work.
The enabled cell uses the official OTLP exporter, a 100 ms HTTP timeout, no
transport retries and a 256-span/64-span-batch queue with automatic flush
disabled. Workers flush at most once per second between queue iterations and
shut down the provider after their worker loop. HTTP requests shut down their
own provider during request teardown. No flush is inserted into an activity or
workflow transaction. The collector decodes official OTLP protobuf messages;
it counts received spans without saving business data. Compare that count with
the independent ended-span count. A healthy comparison with drops is not a
complete-telemetry overhead pass. Collector-outage resilience has its own
functional controls and is not established by this accepting profile.

## Commands

Build the Dockerfile and install `composer.json` normally as UID 1000. Retain
the generated baseline lock for the experiment. For a separate candidate copy,
add a Composer path repository for the exact source with an explicit benchmark
version alias, then update only `durable-workflow/workflow`. Compare every
other locked and loaded package before starting. The alias does not publish
or qualify a package version. Keep `BENCH_APP_DIR` on that cell's complete
installation, including its own `vendor` and the recipe files.

Set `BENCH_PHP_IMAGE`, the resolved MySQL/Redis/Node images, an absolute
`BENCH_STATE_DIR`, a unique Compose project and a unique `PROFILE_NAME`. Create
the `runtime`, `collector` and `results` state subdirectories as UID 1000.
Keep all services on the disposable internal network; no public ports or
production credentials are needed.

```sh
docker compose up -d mysql redis
docker compose run --rm prepare
docker compose up -d collector app --scale worker=4 worker
docker compose run --rm probe
docker compose stop worker app
docker compose run --rm prepare php app.php audit
docker compose stop collector
docker compose down --volumes --remove-orphans
```

Select `MEMBERS=64` for fan-out. Short functional
controls use one offered workflow; their durations and results are not a
measured comparison. Use a fresh project/database for the next cell.
Keep the collector running until all workers and HTTP requests have stopped
and their provider shutdown exports have arrived. Compare ended and received
span counts before stopping it.

Sample every role with the Docker Engine's cumulative CPU and memory counters,
including MySQL, Redis, the collector and the probe. Bound the sampling interval
and report its resolution. Record component and total CPU per completed
workflow, throughput, p50/p95/p99 latency, memory, original deadlines, failed
jobs, retries/repairs, provider errors, ended/received spans and metadata bytes.
Use `app.php audit` only after workers finish; it refuses unfinished work,
extra attempts, duplicate/missing callbacks and queue residue. Automatic
ready-task redispatch counts remain visible and their processing cost is
included. A redispatch of an unclaimed task is distinct from another activity
invocation; the callback and first-attempt checks remain strict.

CPU sampling must include the final provider drain. If the last sample loses
shutdown work or noise masks the 1% target, report that limitation and leave the
target unqualified. Do not turn rounded `docker stats` percentages into precise
CPU totals. The runtime's `ended.jsonl` usage fields are cumulative per process;
use the last value for each container/PID pair rather than summing repeated
HTTP snapshots. Separate containers can use the same PID.
Record ordinary worker returns, signal termination, forced stops and OOM
separately. Preserve failures in the readable result, then remove disposable
databases, provider bytes, images, dependencies and raw samples after review.

## Exact CPU boundaries

For measurements, combine `compose.yaml` with `compose.measure.yaml`. Its small
process retainer records each worker/probe exit code and leaves the container
idle so its final cgroup counters remain readable. It does not restart the
process. Start the probe detached with `docker compose run -d`; a returned
container ID is not completion. Wait for its `probe-*.exit` file and require
zero, then check the result JSON and original workflow outcomes.

The measured probe initially waits for `results/release`. Take the initial CPU
snapshot after all nine roles exist, then create that file to release offers.
An absent release does not admit business work. Keep warmup in a separate probe
and remove its retainer before starting this measured probe.

After the probe and business drain finish, create `runtime/stop-workers` in that
cell's state directory. The public Laravel queue loop requests ordinary worker
exit between jobs, followed by provider shutdown. Require four `worker-*.exit`
files containing zero. A missing/nonzero exit or unfinished original fails the
cell. The HTTP server and collector remain available while exports drain.
Record the child exit separately from later retainer-container SIGTERM.

Run `bash cpu-snapshot.sh PROJECT` on the isolated Linux Docker host before the
offer window, at a declared interval, and after all four worker exits and final
exports. It reads exact cgroup-v2 CPU usage/throttling, memory events and limits
for only that Compose project's measured roles. Require exactly four workers
and one of each other role at every snapshot, with the same container identities
throughout the window. Missing counters or unexpected roles refuse. Keep the
detached probe and worker retainers alive until the last
snapshot. Take the final snapshot before audit queries or teardown, which are
outside the measured business interval. The reported lifetime memory peak
includes startup and warmup; also report the peak sampled within the window.

The retainer and snapshot reader are measurement tools. They do not establish
that a workload meets its observer, overhead target or throughput requirement.

After the last snapshot and audit, stop and remove the exact detached probe
container explicitly. Compose `down` can leave a running one-off retainer even
with `--remove-orphans`. Then retire the ordinary services and volumes, and
verify no container with the experiment's project label remains. Cleanup
failure keeps the aggregate cell unsuccessful even when business work passes.

## Analyze the fixed comparison

After all declared cells finish, copy their `proof/tracing-measure-*` and
`state/tracing-measure-*` directories to a temporary local experiment root.
The state copy must include the worker and probe exit markers. Analysis runs
after measurement and does not share the experiment's CPU allocation.

```sh
node --test analyze.test.mjs
cd /temporary
node /path/to/recipe/analyze.mjs ./experiment-root > ./result.json
```

The reader requires all 20 cells: three baseline/disabled/enabled repetitions
for each workload and one final baseline per workload. It refuses a failed
cell, missing role, changed container or resource limit, decreasing CPU,
missing samples, nonzero child exit, OOM/restart, unfinished business work or
span loss. It normalizes CPU by measured completions, excluding warmup counts.
Metadata, span and audit totals explicitly include warmup.

The declared offer window remains 120 seconds. Node timers use integer-delay
precision, so the probe's actual elapsed value can be fractionally below its
nominal window. The reader preserves that value and requires every cgroup's
counter interval to cover the full declared window, as well as all offers and
original completions. It does not round or rescale measured CPU.

Run from the copied experiment's parent directory. The input root must resolve
inside the working directory. Each data file must resolve inside the selected
experiment root, including symbolic links. This keeps input selection scoped
to the data mounted for analysis.

The fixed statistical method uses paired relative differences and a
conventional two-sided 95% paired-t interval with three pairs, two degrees of
freedom and critical value 4.3026527299. This small sample relies on the usual
t-interval assumptions. A target passes only when both its interval upper
bound and absolute final baseline drift meet the previously declared limit.
Do not select extra repetitions after inspecting the result.

Memory means and container sample peaks describe the observed sampling
interval, not RSS or an exact simultaneous peak. Worker peak fields are the
sum of the four individual container peaks. Lifetime peaks include startup
and warmup. Review the readable result, then delete the copied raw data.
