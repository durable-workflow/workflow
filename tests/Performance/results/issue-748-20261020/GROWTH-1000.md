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

The eight-run pilots and final-source qualification are separate from these results. Earlier failed one-hour and zero-repair audit originals remain failed. No full history/database archive is exported.
