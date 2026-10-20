# Small concurrent and multiworker originals

This study covers eighteen fresh 10-event cohorts on source `ae29003f8308869c3aca84dd0c0ce61b3e0eb31e`, tree `14dffaf0485a0795c85dc78297682825ed8762b8`. Runtime equals database-session-fenced `9a4e0e9e`. Its input lock is SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`. PHP is 8.5.6, Laravel 13.38.0, SQLite 3.51.2 and Avro 1.12.2.

All eighteen cohorts pass, with **102 unique original runs, 1,020 events, 102 unique activity effects, 714 completed tasks and 204 timers**, zero repairs/failed jobs/errors/unfinished work. Exact loaded source/lock, immutable prefixes/deadlines, typed results, clocks and empty-queue/worker-exit controls pass. Every container exits zero without OOM or restart.

Each original cohort uses its own container/database, one CPU / 1 GiB aggregate memory / no swap, actual database jobs and one-second timers. The aggregate includes the controller and all four workers in the multiworker cells. Workers retain four entries each, a nonrenewing 30-second age and the unchanged 128 MiB worker-RSS threshold. No affinity or cache hit is forced. Repetition two reverses mode order. Each pair shares one runner/image; hosted CPU/image identities vary between pairs and remain in the Actions logs.

[One worker / eight runs](https://github.com/durable-workflow/workflow/actions/runs/39453372320), [four workers / one run](https://github.com/durable-workflow/workflow/actions/runs/39453827994) and [four workers / eight runs](https://github.com/durable-workflow/workflow/actions/runs/39453831580) provide all reports, host/image identities and cleanup results. These three topologies do not replace the separately attributed one-worker/one-run study or larger-history acceptance.

## Outcomes

CPU sums actual queue CPU across the worker processes; startup CPU is separate. Peak MiB is the kernel whole-container peak, including startup/audit. Per-process RSS peaks are not added. History SELECTs are cohort totals.

| Workers | Runs | Rep | Mode | Worker CPU s | Queue wall s | Aggregate peak MiB | Entries | Hits / misses | History SELECTs |
| ---: | ---: | ---: | --- | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | 8 | 1 | replay | 3.606557 | 9.943969 | 138.22 | 32 | disabled | 296 |
| 1 | 8 | 1 | retained | 3.862739 | 9.241938 | 138.86 | 28 | 4 / 28 | 352 |
| 1 | 8 | 2 | replay | 2.671366 | 9.296034 | 138.39 | 32 | disabled | 296 |
| 1 | 8 | 2 | retained | 2.764094 | 9.406500 | 138.90 | 28 | 4 / 28 | 352 |
| 1 | 8 | 3 | replay | 3.485510 | 9.844621 | 143.72 | 32 | disabled | 296 |
| 1 | 8 | 3 | retained | 3.527411 | 9.893154 | 144.04 | 28 | 4 / 28 | 352 |
| 4 | 1 | 1 | replay | 1.034570 | 7.086144 | 294.52 | 4 | disabled | 37 |
| 4 | 1 | 1 | retained | 1.161962 | 6.736168 | 306.86 | 3 | 1 / 3 | 44 |
| 4 | 1 | 2 | replay | 0.888284 | 6.560303 | 293.94 | 4 | disabled | 37 |
| 4 | 1 | 2 | retained | 0.896661 | 6.590698 | 293.16 | 3 | 1 / 3 | 44 |
| 4 | 1 | 3 | replay | 0.889351 | 8.744812 | 286.93 | 4 | disabled | 37 |
| 4 | 1 | 3 | retained | 0.934561 | 6.733956 | 300.08 | 4 | 0 / 4 | 44 |
| 4 | 8 | 1 | replay | 4.310177 | 9.579241 | 302.79 | 32 | disabled | 296 |
| 4 | 8 | 1 | retained | 4.403000 | 10.776011 | 308.62 | 28 | 4 / 28 | 352 |
| 4 | 8 | 2 | replay | 3.904036 | 8.587625 | 293.95 | 32 | disabled | 296 |
| 4 | 8 | 2 | retained | 4.107442 | 10.556261 | 302.62 | 31 | 1 / 31 | 352 |
| 4 | 8 | 3 | replay | 3.034582 | 10.238906 | 313.70 | 32 | disabled | 296 |
| 4 | 8 | 3 | retained | 3.121324 | 10.709189 | 311.64 | 27 | 5 / 27 | 352 |

The eight-run, one-worker originals expose actual capacity misses: each has four hits, 28 misses and 20 capacity evictions. Four-worker/single-run originals have zero or one hit; handoff selects cold replay. Four-worker/eight-run originals have one to five hits with 11–15 capacity evictions in total. All logical outcomes remain equivalent and every original completes within its original budget.

Retention uses more worker CPU in every small concurrent/multiworker pair here, with small and variable wall differences. At ten events, completed-prefix savings do not offset cache validation and ordinary misses in these topologies. This supports opt-in deployment chosen from actual reuse measurements; it does not support enabling retention by default or a capacity/speedup claim. All hit/miss counts are observed rather than selected by a forced routing policy.

## Remaining work

The 100/1,000-event concurrent cases, repeated large originals, replay-phase attribution, tracing integration, final source/regression/coverage and published package/consumer qualification remain open. Earlier failures remain failed. These samples qualify their exact declared source and conditions; no whole-tree or performance acceptance transfers to a later candidate.

Raw histories and effect rows are audited in place. Disposable containers, databases and dependencies are removed by the workflow, with no history/database archive uploaded. The readable report and exact source/lock references remain.
