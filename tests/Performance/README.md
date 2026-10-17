# Embedded replay profiling

The first investigation for [#748](https://github.com/durable-workflow/workflow/issues/748)
compares growing histories before choosing a retained-execution design. It
does not add a cache or establish a speedup.

`EmbeddedReplayGrowthProfileTest.php` uses the existing projection-replay
workload: three parallel mocked no-op activities followed by a durable timer, repeated
1, 8 or 83 times, targeting histories near 10, 100 and 1000 events. Report the
actual history size, rather than treating a round count as an event count.
Each size has three independent runs. PHPUnit runs every cell in its own process,
with a fresh in-memory database and an independent peak RSS.

Run explicitly on Linux with the repository's ordinary test dependencies and an isolated
SQLite database:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  PROFILE_REPLAY_GROWTH=1 vendor/bin/phpunit --no-coverage --do-not-cache-result \
  tests/Performance/EmbeddedReplayGrowthProfileTest.php
```

The profile reports:

- Start/admission with its initial synchronous work, then whole ready-task
  batches, including executor replay, persistence and
  synchronous projections: wall/CPU time and SQL counts/time.
- History fetch/envelope mapping and cold bridge Fiber replay as separate operations.
- Idempotent summary projection at the same committed history.
- Workflow entry/prefix visits, actual activity effects, history/task counts
  and process peak RSS.

The operations overlap in purpose and must not be added together. In particular,
the bridge runner is a different execution path from embedded queue jobs. SQL
elapsed time is not database CPU. Peak RSS includes test application startup.
Queued transport, real timer delay, concurrent workers, cold process startup
and a live retained execution are not measured by this initial diagnostic.
The test uses a fake queue and advances the test clock between durable wakes.
It checks committed outcomes and replay without claiming queue or timer latency.

Use a published package installation for the investment decision, record its
exact package/runtime dependencies and resource limits, and report all failed
or unfinished cells. The full #748 comparison still requires the separate
concurrent, real-queue and one-second continuation cases. A retained-execution
implementation also needs a qualified cache identity, authority check,
invalidation and safe Fiber disposal design.

## History/projection role probe

`ProjectionRoleProbeTest.php` measures CPU inside the existing history/projection
interface while preserving the installed default delegate and its optional
interfaces. Nested calls are counted but their CPU is not counted twice. It uses
the same eight-round workload and three independent processes:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  PROFILE_REPLAY_GROWTH=1 vendor/bin/phpunit --no-coverage --do-not-cache-result \
  tests/Performance/ProjectionRoleProbeTest.php
```

### Initial installed-package results, October 17, 2026

Published Workflow **2.5.28**, source `592dee305f46ac707baadf55324407c43aeb2dc5`,
had all 937 source/resource files verified against the tag, with product code
loaded from the normal Composer installation. PHP 8.5.6, Laravel 13.37.0,
Testbench 11.3.0, Avro 1.12.2, SQLite, 1 CPU, 1 GiB and no swap.

The role-probe component passed three tests and 468 assertions. Each original
has 91 events, 24 activity effects, 49 completed first-attempt tasks and zero
repairs. Each invokes `projectRun` 124 times and `recordActivityStarted` 24 times.

| Repetition | Start + ready-task CPU (s) | `projectRun` CPU (s) | Activity-start history + projection CPU (s) |
| --- | ---: | ---: | ---: |
| 1 | 19.182 | 11.900 | 2.318 |
| 2 | 18.719 | 11.605 | 2.259 |
| 3 | 19.445 | 12.081 | 2.342 |

The two delegated role paths account for 74.12% of measured CPU across these
three runs. `recordActivityStarted` includes history recording and projection;
it is not a pure projection measurement. The remaining CPU includes execution,
task handling and other persistence, so it must not be called replay CPU.
These are instrumented diagnostic results, with mocked activities and virtual
timer time. They establish neither a speedup nor production capacity.

The initial growth component also passed three repetitions each at 14 and 91
events. Its larger-history cells and the complete #748 comparison were not
accepted at the time of this report. A short external stack sample had read
errors and partial traces; its percentages are not used here.

The measured projection cost merits investigation before choosing a live-Fiber
cache. Keep that decision open until larger histories and the remaining actual
queue/concurrency profiles distinguish which costs retention can remove.
