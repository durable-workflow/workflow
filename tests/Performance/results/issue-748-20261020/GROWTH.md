# Current-main small growth comparison

This source study covers twelve independent originals: 10/100 events, one persistent worker, one concurrent run, replay/retained modes and three repetitions. It does not qualify larger histories, concurrency, final retained-execution source or published artifacts. Earlier unsuccessful originals remain unchanged.

## Source and conditions

- Source: `0648f9f6a0f5f3ccc06beb89c77959e67ce8f548`; runtime equals `178ad639` and incorporates released 2.7.3 main.
- Input: [committed Composer lock](https://github.com/durable-workflow/workflow/blob/0648f9f6a0f5f3ccc06beb89c77959e67ce8f548/tests/Performance/continuation/COMPOSER-INPUT.lock), SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`.
- PHP 8.5.6, Laravel 13.38.0, SQLite 3.51.2 and Avro 1.12.2. The image uses the committed Dockerfile and immutable Composer base, with actual pcntl support.
- Each pair shares its runner, image, source and dependencies. Each mode uses a fresh container/database with one CPU, 1 GiB aggregate memory, no swap and no network. Hosted CPU/kernel models vary across pairs.
- Full optional observers remain on. Each original retains its 3,600-second execution/run/observation budgets, 30-second worker startup bound and 15-second transport/worker drain bound.
- Three alternating replay/retained pairs per size. Actual database queues, typed activity effects and one-second timers run without mocks or virtual time.

[10-event Actions evidence](https://github.com/durable-workflow/workflow/actions/runs/39449709274) and [100-event Actions evidence](https://github.com/durable-workflow/workflow/actions/runs/39450106760) contain every original report and teardown result. All twelve pass the exact history, result, clock, original deadline, task/effect, source/lock, queue-empty and exit audits. Total: 660 events, 120 unique activity effects, 516 completed tasks and 132 timers, with zero repairs, failed jobs, errors or unfinished runs. Every container exits zero with no OOM or restart.

## Per-original results

CPU and wall values are seconds. Peak MiB is the complete fresh-container cgroup peak, including startup/audit. Worker CPU includes replay, projection, history/model loading, dispatch and activity/timer execution. It is not isolated replay CPU. Worker cold-start CPU is measured separately and is not included in queue CPU.

| Events | Rep | Mode | Worker CPU | Queue wall | Aggregate peak MiB | Authored entries | Hits / misses | History SELECTs |
| ---: | ---: | --- | ---: | ---: | ---: | ---: | --- | ---: |
| 10 | 1 | replay | 0.658683 | 6.787323 | 132.84 | 4 | disabled | 37 |
| 10 | 1 | retained | 0.629675 | 6.737483 | 133.20 | 1 | 3 / 1 | 44 |
| 10 | 2 | replay | 0.383904 | 6.470602 | 133.21 | 4 | disabled | 37 |
| 10 | 2 | retained | 0.384447 | 6.521443 | 133.11 | 1 | 3 / 1 | 44 |
| 10 | 3 | replay | 0.597610 | 6.732288 | 133.20 | 4 | disabled | 37 |
| 10 | 3 | retained | 0.618205 | 6.734843 | 133.27 | 1 | 3 / 1 | 44 |
| 100 | 1 | replay | 14.251240 | 56.073207 | 145.82 | 40 | disabled | 433 |
| 100 | 1 | retained | 13.207043 | 54.963437 | 145.54 | 2 | 38 / 2 | 512 |
| 100 | 2 | replay | 13.113439 | 63.871097 | 145.93 | 40 | disabled | 433 |
| 100 | 2 | retained | 11.819803 | 62.782629 | 145.76 | 2 | 38 / 2 | 512 |
| 100 | 3 | replay | 16.346517 | 55.949345 | 150.43 | 40 | disabled | 433 |
| 100 | 3 | retained | 15.297247 | 55.901072 | 150.57 | 2 | 38 / 2 | 512 |

At ten events, paired CPU changes vary in sign. At 100 events, retained worker CPU is 7.33%, 9.86% and 6.42% lower within the three respective host pairs. These descriptive differences do not establish production savings or an isolated replay saving. No median is pooled across different host models.

Each 100-event retained original avoids 38 completed-prefix re-entries but makes 512 history SELECTs versus 433 for replay. Each has one ordinary nonrenewing age eviction or publication discard. Neither database-read reduction nor removal of history/projection cost is established. SQL elapsed time, all-query counts, seven/79 task-claim delay observations and two/20 timer-lateness observations per respective size remain separate scalar distributions in the Actions reports. They are not database CPU or pure queue overhead.

## Runner/image identities

The image was built once per pair from the same pinned source and base; image IDs differ between hosted builds. Within each pair both modes use the same exact image.

| Events | Rep | Reported CPU model | Image ID |
| ---: | ---: | --- | --- |
| 10 | 1 | AMD EPYC 7763 64-Core Processor | `sha256:22e1f2f563b1b72a8502caf060e8cdec427714bd9ca1bbbfce7f0ff85df1c0a9` |
| 10 | 2 | AMD EPYC 9V45 96-Core Processor | `sha256:3a28b3eab439bd26c34e7ccbaeda8917fef3fb01fbc2d64baae6ab12ee8019ea` |
| 10 | 3 | AMD EPYC 7763 64-Core Processor | `sha256:3d4c4b6921d36595b0d1cbe454c1834816dded5597518379d2b22432646ffa24` |
| 100 | 1 | INTEL(R) XEON(R) PLATINUM 8573C | `sha256:eb8f939f1c0438ee26fcc094ab39fe8d9b699b3f2fa408559ef08d242eb729b8` |
| 100 | 2 | AMD EPYC 9V74 80-Core Processor | `sha256:83a3a3f3da15c25ef029991f78f7e9f65512fcf3e183b545470bbb04321b63ac` |
| 100 | 3 | AMD EPYC 7763 64-Core Processor | `sha256:129dbc4a68136565281b50bc136ff36a724ca1016e97601ba4ca50a4eafe098c` |

Cold test-worker startup CPU ranges from 0.123019 to 0.219365 seconds. It includes PHPUnit/Testbench startup, source checks and opening SQLite, rather than a stock Artisan-only startup. Hosted kernels are Linux 7.0.0-1017-azure except 100-event repetition three, which uses 6.17.0-1022-azure.

## Next acceptance

The small-cell cost supports a bounded next 1,000-event pilot. It does not authorize stable retention. The later database-session identity correction and remaining lifetime controls require their own exact-source qualification. The final tracing integration, replay-phase attribution, concurrent/multiworker hit/miss/pressure cells, complete regression/conformance, published package and affected consumers remain required. No old whole-tree pass or these source measurements transfer to a later candidate.

Only readable measurements and reproducible source/lock remain after audit. Actions cleanup removes disposable installations; containers/databases are task-owned and removed. No database/history archive is retained.
