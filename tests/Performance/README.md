# Embedded replay profiling

The first investigation for [#748](https://github.com/durable-workflow/workflow/issues/748)
compares growing histories before choosing a retained-execution design. It
does not add a cache or establish a speedup.

`EmbeddedReplayGrowthProfileTest.php` uses the existing projection-replay
workload: three parallel mocked no-op activities followed by a durable timer, repeated
1, 8 or 83 times, targeting histories near 10, 100 and 1000 events. Report the
actual history size, rather than treating a round count as an event count.
Each size has three independent runs.

Run explicitly with the repository's ordinary test dependencies and an isolated
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
