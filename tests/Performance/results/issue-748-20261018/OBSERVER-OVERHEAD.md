# Embedded continuation observer comparison

## Scope

This separately declared study measures the optional listeners in the growing-history
probe for [#748](https://github.com/durable-workflow/workflow/issues/748). Its source
is `9ad7d9b14a74e1755c2c377989b3e2e324375301`, tree
`9c906771dbf84c5e603ac22617e11fe25d5e2d3c`, including released main `c301f442`.
The earlier measurements and unsuccessful one-hour pilot retain their original
source and outcomes. Experimental retention remains disabled by default.

## Method

Each cell creates a fresh container, SQLite database and persistent Testbench queue
worker. One workflow runs 19 activity/timer rounds and a final timer, giving
100 events, 19 unique effects and 79 completed durable tasks. Timers wait one real
second. The probe verifies typed bigint/null/Unicode values, contiguous history,
the original admission prefix, recorded workflow clocks and original deadlines,
zero repairs/failed jobs, an empty queue and ordinary worker exit zero.

Replay and retained modes change only the existing allowlist. Retention keeps its
four-entry, 30-second nonrenewing age and 128 MiB worker-RSS thresholds. Actual cache
hits, misses and authored entries are reported. No affinity or hit is forced.

Full observation enables the existing SQL, task-claim delay, timer-lateness and
cache-peak listeners. Minimal observation disables those listeners while retaining
the committed-completion barrier, authored-entry counters, process CPU/memory,
source/lock checks and final audit. Its unavailable optional fields remain null.
It is still a source probe with Testbench, rather than an uninstrumented Artisan
application measurement.

Three matched pairs per execution mode use the order declared before admission.
Repetition1 runs replay/full, replay/minimal, retained/full, retained/minimal.
Repetition2 reverses that order. Repetition3 runs replay/minimal, replay/full,
retained/minimal, retained/full. A final fresh replay/minimal cell checks drift.
Every original keeps 3,600-second run/execution/observation bounds, 30-second worker
startup and 15-second queue/worker drain. Failed originals are not renewed.

The isolated virtual host reports two logical CPUs, Intel Xeon Skylake (IBRS),
full virtualization with hypervisor vendor Microsoft, Linux 6.8.0-146 and
Docker 29.1.3 with overlayfs/cgroupv2. Each measured container has one CPU,
1 GiB aggregate memory and zero swap. NTP synchronization and the kernel aggregate
peak counter were checked before admission. Build/dependency preparation and four
successful functional controls finished first. No competing application/build
container runs during the study. Host steal observations are reported separately.

The PHP tool image is
`docker.io/durableworkflow/server@sha256:cba7c4c6732529293ef6c2625a64785dcb21b4f7fe0013fdc74f1af466f534ac`,
amd64 child `8248d5863b2bd296a0635af5deb181046ee86391f460a331a18f44efa625cdd7`,
config `4199bcbaf349a600cbb29a7ced6170b72c49a51828c39f0e7963dc102b642911`.
Its inherited healthcheck is disabled. PHP 8.3.35, Laravel 13.37.0,
Testbench 11.3.0 and PHPUnit 12.5.40 use one normal dependency installation with
Composer root version 2.6.1. The exact generated input lock is provided beside this report,
SHA-256 `d8d433d6e5e4e4a6d5a9c10a85608b44495636175f89fd04e811bbaf7601cfef`.
All seven loaded runtime/fixture hashes and this lock must match in every worker.

Worker queue CPU excludes its separately recorded cold Testbench startup. Aggregate
workload CPU includes the controller and worker through queue and worker drain,
ending before final audit queries. Aggregate peak memory covers the entire fresh
container, including startup and audit. Per-process RSS peaks are not summed.

## Observations

All thirteen originals pass. The thirteen cells ran from
**12:56:12.652 UTC to 13:14:49.984 UTC on October 18, 2026**. Every cell has its
own original run and container. The source/lock checks, resource limits, recorded
clocks, history/effect/task audits and teardown checks pass. Across the study:
**13 workflows, 1,300 events, 247 unique effects and 1,027 completed durable tasks**,
with zero repairs, failed jobs or unfinished work. All workers and containers exit
zero, with no OOM or restart.

| Cell | Worker CPU | Aggregate CPU | Workload wall | Peak MiB | Authored entries | Cache hits / misses |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| r1-replay-full | 51.423 | 51.893 | 87.637 | 176.48 | 40 | disabled |
| r1-replay-minimal | 51.715 | 52.191 | 88.808 | 176.96 | 40 | disabled |
| r1-retained-full | 42.223 | 42.653 | 79.298 | 176.70 | 3 | 37 / 3 |
| r1-retained-minimal | 42.769 | 43.193 | 76.930 | 176.74 | 3 | 37 / 3 |
| r2-retained-minimal | 42.447 | 42.859 | 76.668 | 176.78 | 3 | 37 / 3 |
| r2-retained-full | 42.717 | 43.135 | 76.771 | 177.00 | 3 | 37 / 3 |
| r2-replay-minimal | 51.964 | 52.440 | 87.037 | 176.51 | 40 | disabled |
| r2-replay-full | 50.594 | 51.044 | 84.670 | 176.52 | 40 | disabled |
| r3-replay-minimal | 50.066 | 50.610 | 101.341 | 176.63 | 40 | disabled |
| r3-replay-full | 49.577 | 50.021 | 83.111 | 176.85 | 40 | disabled |
| r3-retained-minimal | 44.308 | 44.713 | 75.872 | 177.02 | 3 | 37 / 3 |
| r3-retained-full | 43.715 | 44.123 | 75.841 | 176.96 | 3 | 37 / 3 |
| drift-replay-minimal | 49.446 | 49.877 | 82.444 | 176.63 | 40 | disabled |

CPU and wall values are seconds; peak memory is the whole-container MiB peak.
Worker startup CPU is separate and ranges from 0.579 to 0.623 seconds. All six
retained cells have three authored entries, 37 cache hits and three misses. Each
has two age evictions/discards in total; expiry placement changes the individual
`evict_age`/`discard_age` counters without changing the hit/miss totals. Replay
cells enter authored code 40 times. No observation is rescaled or noise-corrected.

| Mode | Full CPU median | Minimal CPU median | Median paired change | Paired range |
| --- | ---: | ---: | ---: | --- |
| replay | 50.594 | 51.715 | -0.98% | -2.64% to -0.56% |
| retained | 42.717 | 42.769 | -1.28% | -1.34% to +0.64% |

Paired change uses `(full / minimal - 1) * 100` for matching repetition numbers.
The final replay/minimal drift control uses **49.445904 worker CPU seconds**
versus the initial **51.714887**, a change of **-4.39%**. Per-cell observed host
steal ranges from 0% to 0.00636%; its cause and the CPU drift are not attributed.

The full-observer cells each contain 79 task-claim-delay observations and 20 timer
lateness observations. Their per-cell p95 values and SQL counts are:

| Full-observer cell | SQL count | History SELECTs | SQL elapsed ms | Claim p95 ms | Timer lateness p95 ms |
| --- | ---: | ---: | ---: | ---: | ---: |
| r1-replay-full | 11298 | 472 | 2916.430 | 1513.439 | 1897.584 |
| r1-retained-full | 11380 | 551 | 2822.430 | 1442.920 | 2010.657 |
| r2-retained-full | 11352 | 551 | 2848.690 | 1397.030 | 1789.932 |
| r2-replay-full | 11294 | 472 | 2863.760 | 1347.265 | 1755.450 |
| r3-replay-full | 11286 | 472 | 2863.240 | 1386.273 | 1811.595 |
| r3-retained-full | 11332 | 551 | 2917.340 | 1507.603 | 1795.700 |

SQL elapsed time is the worker's cumulative reported query duration, not database
CPU. Minimal cells report all these optional fields as null. Retained cells make
551 history SELECTs versus replay's 472 in this workload; no database-read
reduction is established.

## Interpretation and remaining acceptance

The six paired worker-CPU changes range from **-2.64% to +0.64%**, smaller than
the observed **4.39%** baseline drift. This study does not resolve a positive
listener cost. Negative estimates do not establish a benefit from observation,
and no estimated observer cost is subtracted from earlier results.

On this specific source workload, retained and replay worker-CPU medians remain
separated under both observation settings. The full-observer medians are
42.717 and 50.594 seconds; minimal medians are 42.769 and 51.715 seconds.
Every retained cell avoids 37 completed-prefix re-entries while preserving the
original outcome. These are descriptive results from three repetitions, with
material baseline drift; they do not establish a production saving.

The next runtime investigation is repeated history-payload decoding already
identified by `BridgePayloadProbeTest`. A candidate must preserve raw bytes,
mutation/invalidation, serialization and configured-model behavior. Source
correctness and a new declared comparison are required before choosing it or
admitting another larger-history measurement. This result does not justify
relaxing correctness diagnostics or extending the failed one-hour pilot.

This study covers one source workflow size and one worker/run topology. The
remaining 1,000-event, concurrency, hit/miss/pressure, lifetime and published-artifact
requirements of #748 stay open. It does not qualify service-mode warm execution.

## Reproduce

Use source `9ad7d9b1` and copy the adjacent `COMPOSER-INPUT.lock` to its root as
`composer.lock`. Install with `COMPOSER_ROOT_VERSION=2.6.1` in the pinned PHP image,
then keep the source, vendor tree and lock unchanged throughout the comparison.
Use the [existing recipe](../../EMBEDDED-CONTINUATION.md) and one fresh constrained
container per cell, selecting `100-events_1-workers_1-runs_MODE_repeat-N`.
Set `DWF_GROWTH_OBSERVERS=on` or `off` for its declared cell, with
`PROFILE_EMBEDDED_CONTINUATION=measure` and `XDEBUG_MODE=off`.
The drift cell selects a separate fresh replay/minimal repeat1 original. The
experiment host, task access key, source/dependency copies and raw samples are
retired after immediate audit. The input lock and this report remain reproducible.
