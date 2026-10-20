# 1,000-event embedded pilot, October 20, 2026

[Run 39452413954](https://github.com/durable-workflow/workflow/actions/runs/39452413954) completes both fresh originals under their unchanged bounds. Source `ae29003f8308869c3aca84dd0c0ce61b3e0eb31e`, tree `14dffaf0485a0795c85dc78297682825ed8762b8`, one worker/run, one CPU / 1 GiB aggregate memory / no swap. Each mode has its own original 3,600-second run/execution/observation bound, 30-second startup and 15-second drain. The older one-hour failed original remains failed; these are independent observations.

This is one alternating-mode pair, not the required three repetitions or qualification of later claim/observer changes. Intel Xeon Platinum 8573C; built image `sha256:7e9f2b8d2fdc2ad22edc86c6ccf284ca8feb70d57fe1436871a4cb815206c3e7`; PHP 8.5.6, Laravel 13.38.0, SQLite 3.51.2, input lock SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`. Both use the same source, image, lock, queue, real one-second timers and observers. Exact loaded-source hashes are checked for controller/workers.

Both originals pass 1,000 contiguous events, 199 typed activity effects, 799 completed first-attempt tasks, 200 durable timers, preserved admission prefix/original deadlines, correct recorded clocks/results, zero repair counts/failed jobs, empty transport and successful worker drain. Neither container was OOM-killed or restarted.

| Measurement | Replay | Retained |
|---|---:|---:|
| Workload wall seconds | 2319.014 | 1391.140 |
| Queue-worker CPU seconds | 2097.221 | 1170.469 |
| Cgroup workload CPU seconds | 2104.815 | 1174.869 |
| Whole-container peak MiB | 198.61 | 198.67 |
| Worker peak RSS MiB | 126.66 | 127.06 |
| Authored entries | 400 | 39 |
| Authored round visits | 40198 | 4819 |
| History SELECTs | 4393 | 5192 |
| All SQL statements | 266452 | 266781 |

Retained execution records 361 hits / 39 misses, 31 age evictions and seven age publication discards. The nonrenewing 30-second age, four-entry bound and 128 MiB worker-RSS threshold are unchanged. Workers end with zero retained entries. There is no affinity wait or lease extension.

Within this single pair, worker CPU is 44.19% lower and workload wall is 40.01% lower. History SELECTs rise from 4,393 to 5,192. Worker CPU includes history/model loading, replay/forward execution, history/projection, dispatch, transaction, activity and observer work; it does not isolate replay CPU. This pair supports further qualification, not a production speedup/capacity claim or a pooled estimate.

| Delay diagnostic | Replay | Retained |
|---|---:|---:|
| Task-claim delay p50 ms | 699.035 | 714.283 |
| Task-claim delay p95 ms | 1744.408 | 1749.552 |
| Timer lateness p50 ms | 1432.854 | 1460.503 |
| Timer lateness p95 ms | 2276.317 | 2150.371 |

Task delay has 799 observations per original; timer lateness has 200. They are separate elapsed-time distributions, not CPU or a pure transport-overhead estimate. Cold test-worker startup, SQL elapsed, source/image/lock and exit evidence remain in the scalar Actions logs. No database/history archive is published.
