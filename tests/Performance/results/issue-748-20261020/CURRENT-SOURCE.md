# Later source comparisons, October 20, 2026

These fresh comparisons qualify their exact source only. Each mode uses a fresh one-CPU / 1 GiB aggregate / no-swap container, real database queues and one-second durable timers, with the original 3,600-second workflow/execution/observation budget, 30-second startup and 15-second drain. PHP 8.5.6, Laravel 13.38.0 and SQLite 3.51.2 use input lock SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`. Observers are enabled. Each pair shares its host/image/dependency lock; pair order alternates. Hardware and drift prevent pooling the pairs into one production speedup estimate.

CPU is aggregate queue-worker process CPU, including projection/history, dispatch, activities and instrumentation. It is not isolated authored or completed-prefix CPU. Peaks cover the whole container. Process startup, task-claim delay, timer lateness and SQL elapsed remain separate in the scalar Actions records. No history/database archive is published. The [large concurrent failures](GROWTH-1000.md) and all earlier failed originals remain failed.

## 10 events, one worker, one run

[Run 39478490070](https://github.com/durable-workflow/workflow/actions/runs/39478490070), source `fa5d23009245b0c975a685956131ae1e575e49a3`, passes all six independent original cohorts. Each cohort completes its one workflow. Every original passes its full effect/history/clock/deadline/first-attempt/empty-transport/drain audit, with zero errors, unfinished runs, repairs, healthy redispatches, OOM kills or restarts.

| Repetition | Replay wall / CPU seconds | Retained wall / CPU seconds | Peak replay / retained MiB | Retained hits / misses | Retained cold entries |
|---|---:|---:|---:|---:|---:|
| 1 | 7.256 / 0.457 | 7.679 / 0.460 | 133.28 / 133.56 | 3 / 1 | 1 |
| 2 | 6.679 / 0.570 | 6.680 / 0.573 | 133.10 / 133.15 | 3 / 1 | 1 |
| 3 | 6.424 / 0.327 | 6.414 / 0.321 | 133.11 / 133.55 | 3 / 1 | 1 |

Retained execution enters authored PHP once instead of four times and makes 44 history SELECTs versus 37. The tiny CPU differences do not establish a production saving.

| Repetition | CPU model | Built image |
|---|---|---|
| 1 | AMD EPYC 9V74 80-Core Processor | `sha256:51716d016610b184ce9673012d5d812305e3eedbd12b5f0d2462ca81b816b4ed` |
| 2 | AMD EPYC 9V74 80-Core Processor | `sha256:18afc1540745a3f22cb516fb59b983ed77574b0b4ce6335e3ef8b727f1d01268` |
| 3 | AMD EPYC 9V45 96-Core Processor | `sha256:0987209fabe8d61c237696b2aea90d87ac3718a0de436362c6d7d61a24b73c7b` |

## 100 events, four workers, eight runs

[Run 39476212925](https://github.com/durable-workflow/workflow/actions/runs/39476212925), source `5dd594e397184ee1f22a7a5dfaef867e70c530b6`, passes all six independent original cohorts. All 48 workflows complete across the six cohorts. Every original passes its full effect/history/clock/deadline/first-attempt/empty-transport/drain audit, with zero errors, unfinished runs, repairs, healthy redispatches, OOM kills or restarts.

| Repetition | Replay wall / CPU seconds | Retained wall / CPU seconds | Peak replay / retained MiB | Retained hits / misses | Retained cold entries |
|---|---:|---:|---:|---:|---:|
| 1 | 121.294 / 96.579 | 121.409 / 97.193 | 401.11 / 401.69 | 74 / 246 | 246 |
| 2 | 160.208 / 149.864 | 154.898 / 145.200 | 389.95 / 389.99 | 79 / 241 | 241 |
| 3 | 112.237 / 100.527 | 116.635 / 99.882 | 391.10 / 393.28 | 73 / 247 | 247 |

Replay enters authored PHP 320 times per cohort; retained execution enters 246 / 241 / 247 times. The four-entry worker caches remain miss-heavy. History SELECTs increase from 3,464 to 4,096 per cohort. There is no consistent worker-CPU saving or affinity assumption. This source predates the later broad-signature admission correction; the measured integer entry is unchanged by that correction.

| Repetition | CPU model | Built image |
|---|---|---|
| 1 | AMD EPYC 9V74 80-Core Processor | `sha256:0302c1819f88703e067648980ebc5352bd8b8ef4b686a0b5d44b0e5eca9e6ccf` |
| 2 | AMD EPYC 7763 64-Core Processor | `sha256:5f589f8fe959ce2d026f8cce74965d87f18efbda5e22a2ae784f05e13147d1d4` |
| 3 | Intel(R) Xeon(R) 6973P-C | `sha256:9defa74970d3d68592fa66238cd23f525789fc16008cafa37300259db10e1020` |

## 1,000 events, one worker, one run

[Run 39476208605](https://github.com/durable-workflow/workflow/actions/runs/39476208605), source `5dd594e397184ee1f22a7a5dfaef867e70c530b6`, passes all three pairs / six originals. Each cohort completes its workflow with 1,000 contiguous events, 199 typed activity effects, 799 first-attempt tasks and 200 durable timers. Full audits verify the admission prefix, recorded clocks/results, original deadlines, empty transport and graceful drain. There are zero errors, unfinished runs, task repairs, healthy redispatches, OOM kills or restarts.

| Repetition | Replay wall / CPU seconds | Retained wall / CPU seconds | Peak replay / retained MiB | Retained hits / misses | Retained cold entries |
|---|---:|---:|---:|---:|---:|
| 1 | 2820.913 / 2636.072 | 1636.895 / 1458.272 | 193.34 / 193.93 | 356 / 44 | 44 |
| 2 | 2730.400 / 2537.499 | 1584.593 / 1404.618 | 198.80 / 199.65 | 357 / 43 | 43 |
| 3 | 2946.513 / 2767.292 | 1687.810 / 1518.125 | 193.30 / 194.78 | 355 / 45 | 45 |

Replay enters authored PHP 400 times / 40,198 round visits per cohort. Retained execution enters 44 / 43 / 45 times, with 5,544 / 5,369 / 5,647 visits. History SELECTs increase from 4,393 to 5,192 per run; each retained cohort validates 200,399 committed history events. Frames retain their four-entry / nonrenewing 30-second / 128 MiB worker-RSS bounds, and no entries remain after drain. Age misses occur naturally; no cache hit, queue affinity or worker lifetime is forced.

The exclusive CPU breakdown separates repeated durable dispatch from authored PHP. Values are seconds; authored PHP includes forward execution as well as replay, and suspended waits are outside its brackets.

| Repetition | History/projection replay / retained | Durable dispatch replay / retained | Validation replay / retained | Authored PHP replay / retained |
|---|---:|---:|---:|---:|
| 1 | 1164.840 / 1152.885 | 1356.439 / 225.512 | 51.391 / 8.437 | 4.676 / 0.618 |
| 2 | 1132.497 / 1122.816 | 1294.722 / 205.329 | 48.509 / 7.547 | 4.867 / 0.644 |
| 3 | 1208.623 / 1196.243 | 1435.201 / 237.169 | 55.296 / 8.941 | 6.050 / 0.843 |

As in the earlier successful study, repeated dispatch and validation account for most of the observed CPU difference. History/projection remains substantial and authoritative. Whole-worker differences support bounded continuation for this workload; they do not isolate completed-prefix CPU or establish a production saving/capacity envelope. The failed large concurrent originals remain failed. This source predates the later broad-signature admission correction; its measured integer entry remains eligible under that correction. Functional-source correctness and normal published-package acceptance are separately qualified.

| Repetition | CPU model | Built image |
|---|---|---|
| 1 | AMD EPYC 7763 64-Core Processor | `sha256:a4747658e09cb298a0005c3f555e0ef16ecef252ca90f27239054a6671435575` |
| 2 | AMD EPYC 7763 64-Core Processor | `sha256:66f98f5db02ae64c755a379163c61b29fc215853fd57d651d96e70a4ef6666de` |
| 3 | AMD EPYC 7763 64-Core Processor | `sha256:3af1041ef1a47d198ea725f8557ae6f44c9924c80d1e94664a62ace32dc47a49` |
