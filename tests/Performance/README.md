# Embedded replay profiling

The first investigation for [#748](https://github.com/durable-workflow/workflow/issues/748)
compares growing histories before choosing a retained-execution design. It
does not add a cache or establish a speedup.

`EmbeddedReplayGrowthProfileTest.php` uses the existing projection-replay
workload: three parallel mocked no-op activities followed by a durable timer, repeated
1, 9 or 91 times, targeting histories near 10, 100 and 1000 events. Report the
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
- Each cold bridge sample retains its whole-operation measurement and includes
  `phases.initialization` for runner construction/history indexing and
  `phases.step` for workflow execution, replay matching and value delivery.
  Both phases must issue zero SQL queries. The additional timing calls have
  overhead; this diagnostic split is not a runtime optimization or speedup.
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

## Activity identity matching probe

The separate `ActivityIdentityProbeTest.php` uses one genuine 102-event fixture
to call activity-identity validation over its 27 recorded activity positions.
It runs three samples each with ordinary preloaded models, payload counters,
and counters with cast timing. Every matching sample must issue zero SQL; the
whole stored history, effects and task state are checked afterwards. Enable it
with `PROFILE_REPLAY_GROWTH=1`, using the same locked published consumer.

This measures the public identity helper in isolation, not the complete bridge
step or authored workflow replay. Counter/timer overhead changes its cost, and
cast timing includes clock overhead. Do not subtract it from another path or
call its fraction an exact replay attribution. Missing measurements are null.

```sh
cd benchmarks/embedded-replay
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  PROFILE_REPLAY_GROWTH=1 vendor/bin/phpunit --configuration ../../phpunit.xml \
  --bootstrap vendor/autoload.php --no-coverage --do-not-record-test-run-history \
  ../../tests/Performance/ActivityIdentityProbeTest.php
```

### Initial helper measurement, October 17, 2026

The unchanged published 2.5.28 consumer on PHP 8.5.6, Laravel 13.37.0 and
Testbench 11.3.0 passed one genuine original with 102 events, 27 activity
effects, 55 first-attempt tasks and zero repairs. All 27 activity snapshots
were present; the calls supplied no explicit retry overrides. The stored
history remained unchanged. The container had one CPU, 1 GiB memory and no
swap on an Intel i5-6500 host.

Each sample checked all 27 recorded positions; each mode had three samples:

| Mode | Median process CPU | Payload casts per sample | SQL per sample |
| --- | ---: | ---: | ---: |
| Ordinary models | 38.057 ms | Unmeasured | 0 |
| Counters without cast clocks | 39.544 ms | 2,754 | 0 |
| Counters with cast clocks | 51.408 ms | 2,754 | 0 |

Each instrumented sample presented 5,039,100 JSON bytes to the model cast,
including repeat reads. Of its 2,754 casts, 918 were activity-scheduled
payloads and 1,836 were other event types. This identifies repeated payload
decoding inside the helper. It does not measure the remaining bridge work,
prove a cache design or establish a speedup.

## Cold bridge payload probe

`BridgePayloadProbeTest.php` uses the same genuine 102-event workload and the
normal `WorkflowFiberRunner::forClass` constructor. A Laravel resolving callback
captures its workflow object. Ordinary samples use the runner-created transient
models. Instrumented samples replace them after initialization with the existing
profiling subclass, preserving raw attributes, original state, casts, connection,
table, relations and persistence flags. The complete command output must match
across all three modes. Product code is loaded from the normal published lock.

The probe measures three fresh cold steps per mode, separating initialization
from step CPU and requiring zero SQL in both. It counts model payload access by
event type, authored workflow entries and round visits, while preserving stored
history, effects and task state. Cast clocks include their own overhead. The
unmeasured remainder is not an exact authored-code CPU measurement.

```sh
cd benchmarks/embedded-replay
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  PROFILE_REPLAY_GROWTH=1 vendor/bin/phpunit --configuration ../../phpunit.xml \
  --bootstrap vendor/autoload.php --no-coverage --do-not-record-test-run-history \
  ../../tests/Performance/BridgePayloadProbeTest.php
```

## Active-stack sampling of cold replay

Set `PROFILE_REPLAY_ORDINARY_ONLY=1` with `PROFILE_REPLAY_GROWTH=1` to run only
the three ordinary-model bridge samples. This leaves payload counters and cast
clocks disabled. The default still runs all three modes above. An external
sampler must observe PHPUnit's PHP child process, not just its waiting parent.
Also set `PROFILE_REPLAY_SAMPLE_WINDOWS=1` to emit that child's PID and physical
start/end timestamps around each measured step. Those optional timestamps do
not change the workflow clock. Select complete stack records by PID and that
window, retaining the leaf functions when distinguishing authored instructions
from framework calls. Include stacks inside the authored Fiber even if its
parent call chain is unavailable. Initialization and application startup are
outside each selected span.

Before trusting a profiler across Fiber suspension, run
`benchmarks/embedded-replay/fiber-profiler-control.php plain` and `heavy`, with
at least three repetitions each. Both execute identical short arithmetic work
inside a Fiber. The heavy case performs a separate inline caller loop while
the Fiber is suspended. Its CPU must not become the authored function's self
CPU. The fixture checks both checksums and records process CPU. For an external
sampler, its one-second setup sleep precedes measured work; exclude that sleep
from attribution. `FIBER_PROFILER=xhprof` or `spx` enables those extensions
explicitly and refuses a missing extension. XHProf emits its original raw
metrics for analysis with the tool's own flat-profile helper; SPX writes its
own report. No profiler is installed or enabled for normal tests.

Sampling reports observed active-stack counts, not exact function CPU. Record
the profiler revision, requested rate, actual sample counts, missed/read-error
samples, process CPU with and without observation, and the exact published
package cohort. Keep the committed 2.5.28 consumer unchanged when measuring a
later release. Do not use samples from counter-instrumented runs to claim an
ordinary-model CPU share.

The [October 17 Fiber qualification and installed 2.5.30 sample](results/issue-748-20261017/FIBER-SAMPLING.md)
records the rejected observers, actual replay windows, process CPU, sample
counts and remaining uncertainty.

## History/projection role probe

Set `PROFILE_EMBEDDED_SAMPLE_WINDOWS=1` to add each original's PHP child PID,
physical measurement start/end, SQL count/elapsed time and process/PHP peak
memory. The window covers workflow start and ready-task handling, before the
validation queries. SQL elapsed time is not SQL CPU. Leave
`PROFILE_HISTORY_ACCESS` unset when sampling ordinary history models.

This probe's fake dispatcher invokes the actual embedded job handlers through
`TaskDispatcher`, including `RunWorkflowTask::handle` and `WorkflowExecutor::run`.
It does not use the separate cold `WorkflowFiberRunner` path for those tasks.
It also does not measure queue transport, worker replacement or real timer
delay. Do not use bridge-only CPU samples to predict an embedded warm-cache
benefit. Keep physical sample windows separate from virtual workflow time,
and include active authored-Fiber stacks in the observer selection.

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

The role-probe component at `51f5432b86f56f9fcf5a0a1a08a37706f881b467`
passed three tests and 468 assertions. Each original
has 91 events, 24 activity effects, 49 completed first-attempt tasks and zero
repairs. Each invokes `projectRun` 124 times and `recordActivityStarted` 24 times.

| Repetition | Start + ready-task CPU (s) | `projectRun` CPU (s) | Activity-start history + projection CPU (s) |
| --- | ---: | ---: | ---: |
| 1 | 18.907 | 11.718 | 2.278 |
| 2 | 18.958 | 11.745 | 2.289 |
| 3 | 19.177 | 11.904 | 2.310 |

The two delegated role paths account for 74.06% of measured CPU across these
three runs. `recordActivityStarted` includes history recording and projection;
it is not a pure projection measurement. The remaining CPU includes execution,
task handling and other persistence, so it must not be called replay CPU.
These are instrumented diagnostic results, with mocked activities and virtual
timer time. They establish neither a speedup nor production capacity.

The initial growth component used 1/8/83 rounds and passed three repetitions each
at 14 and 91 events. The first large cell was interrupted after about 40 CPU
minutes without a result or phase-level progress. Its two remaining large cells
were unstarted. This is an incomplete pilot. A short external stack sample had
read errors and partial traces; its percentages are not used here.

The calibrated defaults now use 1/9/91 rounds and report live phase/counter
progress. Each growth cell atomically replaces a small `progress.json` in its
private `dw-replay-*` directory under PHP's temporary directory. Read it from
the same host or container as the test. It contains the process ID, phase,
iteration, activity effects and workflow visits. Progress writes are outside
the measured operations. Normal teardown removes that cell's directory.
Choose an observation budget before a large run and record an interrupted
cell as incomplete. The full #748 comparison remains open.

The measured projection cost merits investigation before choosing a live-Fiber
cache. Keep that decision open until larger histories and the remaining actual
queue/concurrency profiles distinguish which costs retention can remove.

### Optional history-payload access counts

Set `PROFILE_HISTORY_ACCESS=1` when running `ProjectionRoleProbeTest.php` to
count history-model payload reads during start and ready-task execution. The
probe uses the supported history-model override and always delegates to the
released model accessor. It does not cache, replace or skip a payload cast.

The additional fields report total reads, distinct persisted event IDs, the
largest read count for one event and the sum of raw JSON lengths presented to
the model's payload accessor. Repeated accesses count repeatedly. These are
access counters, not fetched bytes, unique memory or a measured decoding cost.
They include every caller in the measured execution, not only the projection
role. The probe also records actual parent payload-cast calls and CPU measured
around them. Cast CPU includes clock-read overhead and the complete inherited
cast path; it is not pure JSON-decoder CPU. The override adds instrumentation
overhead, so its CPU totals must not be used as a performance comparison with
the uninstrumented baseline. These measurements overlap the role timings and
must not be added to them.

```sh
cd benchmarks/embedded-replay
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  QUEUE_CONNECTION=sync PROFILE_REPLAY_GROWTH=1 PROFILE_HISTORY_ACCESS=1 \
  vendor/bin/phpunit --configuration ../../phpunit.xml \
  --bootstrap vendor/autoload.php --no-coverage --do-not-record-test-run-history \
  ../../tests/Performance/ProjectionRoleProbeTest.php
```

#### Installed-package access observations, October 17, 2026

The separate cast-timed probe on the same published 2.5.28 tuple above passed
three tests / 483 assertions. Each original retained 91 events, 24 mocked
activity effects and 49 completed first-attempt tasks with zero repairs.
Each recorded 430,164 payload accesses and inherited casts across all 91 event
IDs, with a maximum of 9,789 reads for one event. The cumulative raw JSON length
was 780,850,080 bytes. These bytes were repeatedly accessed in memory, not
fetched or retained as a diagnostic artifact.

| Repetition | Instrumented whole CPU (s) | Instrumented payload-cast CPU (s) |
| --- | ---: | ---: |
| 1 | 20.861 | 5.715 |
| 2 | 20.658 | 5.628 |
| 3 | 20.293 | 5.560 |

The inherited cast timing includes clock-read overhead, so it does not establish
a pure decoding share or a potential speedup. It confirms repeated conversion
as one cost to investigate alongside projection work. No model cache, live
execution cache, result change or reduced observation budget was used. The
ordinary accessor path separately passes one test / 156 assertions on the final
probe source; the earlier access-count-only component passed three ordinary
controls / 468 assertions and three instrumented controls / 477 assertions.

## Reproduce the published-package cohort

The ordinary repository commands above exercise source. For the reported
published package, use the separate consumer project and its committed lockfile
with PHP 8.5.6. Its product classes load from the installed package, while only
the test harness loads from this checkout. No path or VCS repository overrides
are configured.

```sh
cd benchmarks/embedded-replay
composer install
composer show --locked durable-workflow/workflow
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array \
  QUEUE_CONNECTION=sync PROFILE_REPLAY_GROWTH=1 \
  vendor/bin/phpunit --configuration ../../phpunit.xml \
  --bootstrap vendor/autoload.php --no-coverage --do-not-record-test-run-history \
  ../../tests/Performance/ProjectionRoleProbeTest.php
```

Select `EmbeddedReplayGrowthProfileTest.php` instead for the growth profile.
The reported PHPUnit 13 runs passed with one deprecated `--do-not-cache-result`
option notice; the locked consumer command uses its replacement above.

## Hosted growing-history run

Manually dispatch **Published embedded replay profile** to run the locked
published consumer. `all` selects the 10/100/1000-event targets, `small` selects
10/100 for setup verification, and `large` selects only the 1000-event target.
Actual event counts remain authoritative. Each of three jobs runs one
repetition, with every selected size in its own PHP process on that job's host.

The workflow records the host CPU model and uses a pinned PHP 8.5.6 image,
1 CPU, 1 GiB and no swap. It verifies all installed source/resources against
the released commit. Live scalar phase/counter reports and completed results
go to the job log; no database, history or profiler archive is uploaded.

Each repetition has a fixed 105-minute observation cap. A cap, interruption
or incomplete cell supplies no passing large-case result. Setup smoke runs
are separate from full comparisons. These hosted diagnostics retain the fake
queue, mocked activities and virtual timer time described above.
