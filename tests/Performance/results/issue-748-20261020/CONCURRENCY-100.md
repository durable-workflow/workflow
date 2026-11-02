# 100-event concurrent embedded execution, October 20, 2026

These are original observations on source `ae29003f8308869c3aca84dd0c0ce61b3e0eb31e`, tree `14dffaf0485a0795c85dc78297682825ed8762b8`. They predate the claimed-attempt correction and revised maintenance audit; they do not qualify those later changes or a release. The [small comparison](GROWTH.md) describes the workload and [10-event concurrency report](CONCURRENCY.md) covers the smaller cohorts.

Each mode uses a fresh container with one CPU, 1 GiB aggregate memory and no swap, the same locked dependencies and original 3,600-second workflow/observation budget, 30-second startup and 15-second drain bounds. PHP 8.5.6, Laravel 13.38.0, SQLite 3.51.2; input lock SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`. Optional observers are enabled. Real database queues, activities and one-second timers are used.

Of eighteen original cohorts, seventeen pass all of their then-current audit gates (94 workflows). One eight-workflow replay cohort fails its zero-repair gate after all workflows completed; the later semantic gates did not run for that original. It remains failed. No cohort was resumed or rebudgeted. No container was OOM-killed or restarted.

Wall and CPU are seconds; peak is whole-container MiB. CPU is aggregate queue-worker CPU, including history/projection, hydration, dispatch, activity and observer work. It is not isolated replay or authored-code CPU. Host/image facts are per pair; host variation prevents pooling these as one production speedup estimate.

| Workers / runs | Repetition | Replay wall / CPU | Retained wall / CPU | Peak replay / retained MiB | Retained hits / misses | Retained cold entries |
|---|---:|---:|---:|---:|---:|---:|
| 1 / 8 | 1 | 153.127 / 136.867 | 156.291 / 142.164 | 163.72 / 165.04 | 4 / 316 | 316 |
| 1 / 8 | 2 | 144.860 / 133.868 | 149.935 / 133.932 | 169.69 / 169.62 | 5 / 315 | 315 |
| 1 / 8 | 3 | 148.988 / 96.109 | 141.525 / 96.241 | 164.61 / 164.91 | 20 / 300 | 300 |
| 4 / 1 | 1 | 47.546 / 14.857 | 47.889 / 13.974 | 329.47 / 328.14 | 34 / 6 | 6 |
| 4 / 1 | 2 | 51.194 / 18.207 | 51.215 / 16.863 | 341.84 / 340.36 | 32 / 8 | 8 |
| 4 / 1 | 3 | 47.410 / 9.631 | 47.440 / 9.177 | 338.35 / 340.92 | 33 / 7 | 7 |
| 4 / 8 | 1 | failed audit; no complete report | 148.638 / 138.698 | unreported / 400.22 | 64 / 256 | 256 |
| 4 / 8 | 2 | 133.584 / 121.412 | 131.942 / 119.423 | 389.56 / 391.18 | 68 / 252 | 252 |
| 4 / 8 | 3 | 111.692 / 93.936 | 108.828 / 94.428 | 389.29 / 389.83 | 73 / 247 | 247 |

The one-worker/eight-run retained cohorts are miss-heavy: 4–20 hits versus 300–316 misses, with 292–308 capacity evictions. Their worker CPU does not decrease. Four workers with one run obtain 32–34 hits, but still do 512 history SELECTs per workflow versus replay's 433. Four workers with eight runs obtain 64–73 hits versus 247–256 misses. There is no affinity wait or cache-threshold change. All successful eight-run cells make 4,096 retained versus 3,464 replay history SELECTs. Avoiding authored re-entry does not remove authoritative history/projection work.

## Failed original

[Run 39455106192](https://github.com/durable-workflow/workflow/actions/runs/39455106192), replay repetition 1, completed eight workflows, 800 history events, 152 activity effects and 632 tasks, with no failed jobs and an empty transport queue. One activity task had `repair_count=1`, `attempt_count=1`. Its available time was `16:16:46.903153`, last dispatch `16:16:47.008060`, and first lease `16:16:53.232293` UTC. The audit failed at the original zero-repair assertion; this is not a complete semantic pass.

Source review shows the watchdog can redispatch a healthy Ready task after its ordinary stale-dispatch threshold when no unreserved broker delivery remains. A delivery can be reserved before its logical claim. This is consistent with the diagnostic timing; it is not a reproduction of a lost effect, expired execution lease or customer defect. Fresh controls on `70ed2c27` reserve a real database-queue delivery across the threshold, then require one logical attempt, one business effect and acknowledgement of the obsolete delivery. Separate entry-repair and killed-worker controls classify actual error repair and expired-lease recovery. The revised future audit reconciles every persisted repair-count increment with these observations, rejects lease/error recovery and still requires original IDs/history/deadlines and first-attempt tasks. It does not change the old result or product timing policy.

## Exact observation receipts

| Workers / runs | Repetition | CPU model | Built image |
|---|---:|---|---|
| 1 / 8 | 1 | AMD EPYC 7763 64-Core Processor | `sha256:1606a56e22bfd271600d64ebdf8dd3c915384cba4185de0244f673d632951335` |
| 1 / 8 | 2 | AMD EPYC 7763 64-Core Processor | `sha256:a03c5e2f1fce1ef5531cac19f323f0bc040ec46e1c0de581d036f1a10da94de2` |
| 1 / 8 | 3 | AMD EPYC 9V74 80-Core Processor | `sha256:3f42585f85b8d1dfebdf0583f139aa872fe8435ef32391cbba86efc92c611a97` |
| 4 / 1 | 1 | Intel(R) Xeon(R) 6973P-C | `sha256:923f8a5dbe12c94accca00feedd5b4a8795de9f747a5c09492295ff570d7ebd5` |
| 4 / 1 | 2 | AMD EPYC 7763 64-Core Processor | `sha256:02fe7d283ca4965ab57b73d11243af66c27aaa777ccfd988de2780fa5f771074` |
| 4 / 1 | 3 | AMD EPYC 9V45 96-Core Processor | `sha256:76139b076d479a7211ea5f47cc0a53c9e031333d04ad0a52e434b7777d5f2ac6` |
| 4 / 8 | 1 | AMD EPYC 7763 64-Core Processor | `sha256:9af36305b63ea727ac70cf853434432fe1004e62336323b75d787f5513f454bc` |
| 4 / 8 | 2 | INTEL(R) XEON(R) PLATINUM 8573C | `sha256:9aa95ba937f51a1fcb24311043ca79a9e9f3fd76123a76d430f781badcf7c499` |
| 4 / 8 | 3 | Intel(R) Xeon(R) 6973P-C | `sha256:27f55debbbc65cd8c615c57194848f565c16d547310b1aa31b1f9b9e6bd254fd` |

Actions retain scalar reports, source/image/lock checks, startup CPU, query elapsed time, task-claim delay and timer-lateness distributions: [1 workers / 8 runs](https://github.com/durable-workflow/workflow/actions/runs/39454539611), [4 workers / 1 runs](https://github.com/durable-workflow/workflow/actions/runs/39455101819), [4 workers / 8 runs](https://github.com/durable-workflow/workflow/actions/runs/39455106192). No database/history archive is published.
