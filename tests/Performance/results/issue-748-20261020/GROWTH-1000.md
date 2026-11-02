# 1,000-event original studies, October 20, 2026

These observations preserve every failed original and qualify only their stated source. They do not establish release or customer capacity. Each mode has a fresh one-CPU / 1 GiB / no-swap container, real database queues and one-second durable timers, with its original 3,600-second execution/run/observation bound, 30-second startup and 15-second drain. No failed original was resumed, retried or rebudgeted. PHP 8.5.6, Laravel 13.38.0, SQLite 3.51.2; input lock SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`.

## Single worker, single run

[Run 39462444846](https://github.com/durable-workflow/workflow/actions/runs/39462444846), source `e3f148bc307534b52162c1e5e9d2abe6a06fdef2`, passes all six original cohorts. Every cohort verifies 1,000 contiguous events, 199 typed activity effects, 799 first-attempt tasks, 200 durable timers, identical recorded clocks/results and admission prefix, original deadlines, empty transport and graceful drain. There are zero task repairs, failed jobs, unfinished runs, OOM kills or restarts. This source predates the configured-model and broker-acknowledgement corrections.

Wall/CPU are seconds; peak is whole-container MiB. Worker CPU includes history/projection, hydration, dispatch, activities and observer work. It is not isolated replay or completed-prefix CPU. Alternating order reduces ordering bias but does not remove same-host drift.

| Repetition | Replay wall / CPU | Retained wall / CPU | Peak replay / retained MiB | Retained hits / misses | Retained cold entries |
|---|---:|---:|---:|---:|---:|
| 1 | 1648.952 / 1363.637 | 956.231 / 659.998 | 198.87 / 200.20 | 371 / 29 | 29 |
| 2 | 2653.723 / 2472.823 | 1569.279 / 1367.728 | 198.86 / 198.85 | 357 / 43 | 43 |
| 3 | 2792.010 / 2612.896 | 1647.070 / 1454.366 | 193.50 / 194.18 | 356 / 44 | 44 |

Replay enters authored PHP 400 times / 40,198 round visits per cohort. Retained execution enters 29/43/44 times / 3,530/5,397/5,485 visits. History SELECTs increase from 4,393 to 5,192 per run; retention does not remove authoritative history/projection work. Every retained cohort validates 200,399 committed history events. Nonrenewing age, four-entry limit and worker RSS threshold are unchanged, with zero entries after drain. Worker peak RSS spans 125.60–128.52 MiB. These process-wide CPU differences support continued bounded retention qualification; they are not a production speedup estimate.

| Repetition | CPU model | Built image |
|---|---|---|
| 1 | AMD EPYC 9V45 96-Core Processor | `sha256:2948f3b5c1f3fb955a1ac157545565cdce7f2faaad969903e91cc35022441659` |
| 2 | AMD EPYC 7763 64-Core Processor | `sha256:2572fc49363185de885d2cab02c321923073b690bc60ce3f7dc70fdd2c9e1438` |
| 3 | AMD EPYC 7763 64-Core Processor | `sha256:36e01cb9d47d712e950e8d45499c4f50efc02a92dac1bd555393bffe53d5c94b` |

## Four workers, single run

[Run 39463828599](https://github.com/durable-workflow/workflow/actions/runs/39463828599), source `9ee8d64115f62e8a6ea0ba1fa9b94021bcc4f89d`, fails all six original cohorts. Each has its sole workflow unfinished. Three containers report OOM; the remaining three report ordinary Laravel worker memory-limit exits (12). The per-worker Laravel allocation threshold is 256 MiB, not an RSS hard limit. All six have failed broker jobs, with available exception samples showing SQLite `database is locked` in projection writes. No task has a repair-count increment. Root cause and applicability to released cold execution are unproven; this is not evidence of customer impact or a retained-frame-specific defect.

| Repetition / mode | Elapsed seconds | Events / effects | Completed / total tasks | Failed jobs | Container OOM |
|---|---:|---:|---:|---:|---|
| 1 / replay | 1278.776 | 729 / 146 | 581 / 582 | 40 | yes |
| 1 / retained | 1258.415 | 760 / 152 | 606 / 607 | 41 | yes |
| 2 / replay | 1346.031 | 832 / 166 | 664 / 665 | 38 | no; worker memory exit |
| 2 / retained | 1213.359 | 856 / 171 | 683 / 684 | 32 | no; worker memory exit |
| 3 / replay | 1387.909 | 830 / 166 | 662 / 663 | 37 | yes |
| 3 / retained | 1258.486 | 840 / 168 | 670 / 671 | 37 | no; worker memory exit |

Failures occurred before the original observation deadline. Each container exits 1 with zero restarts. The failure path retained counts, bounded worker-log tails and OOM flags, but did not publish final worker CPU/RSS JSON. Those values are unavailable; no successful-subset speedup or full semantic pass is inferred. A subsequent diagnostic-only change publishes any already available final worker reports and current process exit states before cleanup; it cannot recover or replace these observations.

## Eight-run admission pilots

Both pilots use source `9ee8d64115f62e8a6ea0ba1fa9b94021bcc4f89d`, the same original limits and repetition 1. [One worker / eight runs](https://github.com/durable-workflow/workflow/actions/runs/39463831372) and [four workers / eight runs](https://github.com/durable-workflow/workflow/actions/runs/39463834650) each fail both independent modes at the original observation deadline. All eight workflows remain unfinished in every cohort; no complete semantic or drain audit follows.

| Workers / mode | Elapsed seconds | Events / effects | Completed / total tasks | Tasks with positive repair count | Failed jobs |
|---|---:|---:|---:|---:|---:|
| 1 / replay | 3599.893 | 5123 / 1022 | 4085 / 4093 | 0 | 0 |
| 1 / retained | 3599.851 | 5151 / 1027 | 4108 / 4116 | 0 | 0 |
| 4 / replay | 3599.773 | 3767 / 751 | 3000 / 3008 | 7 | 57 |
| 4 / retained | 3599.816 | 3822 / 762 | 3044 / 3052 | 11 | 62 |

All four containers exit 1 without OOM or restart. The four-worker samples include SQLite lock exceptions; retained execution also samples `MaxAttemptsExceededException`. The positive repair counts are not classified as healthy pre-claim redispatch or lease recovery without corresponding evidence. Final worker CPU/RSS reports were not published by this older failure path and remain unavailable. Partial progress is not throughput acceptance or evidence of equivalent completed results.

The one-worker host is AMD EPYC 9V45, built image `sha256:d36b47b1bf5f13ffd5dd3a0fbd5703db9d5b81303d5a7641ec7dc9a6e71e1a8f`. The four-worker host is AMD EPYC 7763, image `sha256:fa52f1c48e248c04c0ff6630a153cdc9ac394c07f236f1622ff650f4aac941f0`.

The predeclared admission rule requires pilot review before the remaining large concurrent repetitions. These failed pilots do not justify admitting those repetitions. They remain unrun; this report does not claim a completed 72-original grid. The completed three-pair smaller and single-run comparisons support an opt-in, workload-specific feature with explicit capacity limits. They do not establish a safe concurrent SQLite envelope, and retention is not a remedy for the failed capacity cells.

## CPU interpretation and product decision

The successful single-worker study separates actual Fiber execution from history/projection and repeated durable dispatch. Exclusive CPU seconds (nested buckets excluded) are:

| Repetition | History/projection replay / retained | Durable dispatch replay / retained | Step validation replay / retained | Authored PHP replay / retained |
|---|---:|---:|---:|---:|
| 1 | 603.216 / 546.736 | 694.478 / 71.487 | 25.759 / 2.581 | 3.005 / 0.182 |
| 2 | 1103.905 / 1090.913 | 1263.542 / 203.501 | 47.015 / 7.469 | 3.789 / 0.527 |
| 3 | 1153.902 / 1155.735 | 1344.703 / 218.270 | 50.882 / 8.121 | 5.252 / 0.723 |

Repeated durable dispatch and validation dominate the observed saving, rather than authored PHP itself. Dispatch/validation calls decrease from 80,200 to 7,419 / 11,133 / 11,307; history/projection role calls remain 2,396 in each mode. These phases include forward execution, and the process-wide observer adds work. They do not isolate completed-prefix CPU or establish a general production correction factor. The [same-host observer/drift study](EXECUTOR-CPU.md) preserves that uncertainty.

The selected product remains bounded, explicitly enabled continuation for eligible sequential workflows. History/projection stays authoritative; unsupported features and missing or invalid local frames use ordinary replay. Operators must qualify their own history size, database and concurrency, retain normal worker supervision, and account for both additional history reads and cache misses. Earlier failed one-hour and zero-repair audit originals remain failed. Later source qualification is separately attributed. No full history/database archive is exported.
