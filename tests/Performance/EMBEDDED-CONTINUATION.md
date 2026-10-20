# Embedded continuation growth probe

This is an opt-in source probe for [#748](https://github.com/durable-workflow/workflow/issues/748).
It does not run in the ordinary unit or feature suites. Functional setup results
do not establish a speedup, capacity, production readiness or published-package
acceptance.

## Source correctness controls

Nexus service operations use cold replay after a retained activity prefix. The
unit controls require that replay before both service-call record creation and
backend admission, then check one activity/backend start, typed completion,
unchanged history prefixes and deadlines, and first-attempt tasks without repairs.
They use the real embedded service control plane, history and queue paths;
the downstream workflow-launch boundary is mocked. Service-operation frames
remain outside the retained allowlist.

```sh
vendor/bin/phpunit tests/Unit/V2/EmbeddedContinuationTest.php --filter nexus
```

## Workload and invariants

Each original uses an actual Laravel database queue and real one-second timers.
There is no fake queue, activity mock, virtual clock or history rewrite. Every
round executes one typed activity and one timer; a final timer gives exactly
`5 * rounds + 5` events: 10, 100 or 1,000 for 1, 19 or 199 rounds. The activity
records every invocation in an isolated effect table. The audit requires one
effect per round, exact bigint/null/Unicode values, contiguous history, preserved
admission prefix, original deadlines, completed first-attempt tasks and no
lease recovery or error repair. Every increment of persisted task `repair_count`
must reconcile with an observed healthy Ready-to-Ready transport redispatch
before that task's first claim. These redispatches are reported separately;
they are possible when a broker delivery is reserved before its logical task
is claimed. They do not permit a repeated activity invocation, new task attempt,
expired lease, changed deadline or unfinished delivery.

The observer's classifications have controls using a real held database-queue
reservation, entry-argument repair and SIGKILL followed by expired-lease recovery.
The earlier four-worker/eight-run 100-event original that failed its zero-repair
gate remains failed; the revised audit applies only to fresh observations.

Workflow time begins at the persisted run's `started_at`, as required by the
existing executor, then advances to recorded activity completions and timer
firings. The `WorkflowStarted` event can be recorded later than `started_at`;
the probe does not substitute its recording timestamp for the initial clock.

Replay and retained modes change only the existing live-execution allowlist.
Both use the same source, workflow, policy and budgets. Retention keeps its
four-entry, 30-second nonrenewing age and 128 MiB worker-RSS thresholds. Neither
queue affinity nor a cache hit is forced. One/eight concurrent runs and one/four
workers expose ordinary reuse, handoff and capacity misses. Report actual counters;
do not label a cell hit-heavy merely because it was intended to be.

## Execution and measurements

The controller prepares a fresh database. Each Symfony child process boots its
own real Testbench worker application without migrating that database. A readiness
barrier separates actual cold test-worker startup from admission and queue execution.
This startup includes PHPUnit/Testbench, source verification and opening SQLite;
it is not a stock application's Artisan-only startup measurement.
All workers start from the same source; their loaded hashes and PIDs are checked.
Every run completes through ordinary `queue:work`, and workers drain successfully
after committed completion markers. Startup is not added to or hidden inside the
reported steady queue CPU. The cgroup workload CPU also includes controller and
worker-drain overhead; it is not pure replay CPU.
After committed workflow completion, workers consume remaining queued transport
deliveries before receiving the stop marker. Queue consumption and worker exit
share the original 15-second drain bound; an empty-queue check is still required.

SQL counts and elapsed time, task-claim delay, timer lateness, authored entries,
round visits, cache counters and observed entry counts come from in-process
observers. They add overhead. SQL elapsed is not database CPU. Queue CPU includes
projection, serialization, transaction and activity work. Per-worker peak RSS
includes startup; process peaks are not simultaneous aggregate peaks. The cgroup
peak covers the whole fresh container and is reported separately. Retained-frame
bytes are not isolated from the worker's other memory. Small observer-free
comparisons are still required before attributing a production saving.

With observers enabled, `execution_profile` records process CPU microseconds
and monotonic wall nanoseconds at actual executor boundaries:

- `initial_history_load`: the executor's initial relation/history load and
  hydration, including its SQL wait; this is not every database read in a task.
- `initialization`: definition resolution, cache validation, argument binding
  and initial execution setup.
- `step_validation`: operation/history compatibility and transaction-size guards.
- `durable_call_dispatch`: recorded-outcome lookup, new durable work, completion
  and the handlers' remaining work.
- `authoring_start`, `authoring_resume`, `authoring_throw`: actual Fiber
  execution between entry/resumption and suspension/return/throw. Suspended
  queue/timer waits are outside these brackets. These include authored PHP and
  its workflow API calls, and include forward execution as well as replayed
  instructions; they are not an isolated completed-prefix CPU estimate.
- `history_projection`: the existing history/projection role's project-run,
  activity-start and repaired-task calls, including their SQL and history work.
- `executor`: the complete embedded executor call, including the phases above.

Each bucket reports calls, inclusive CPU/wall and CPU/wall excluding nested
buckets. Sum exclusive values when reconciling phases; inclusive values overlap.
Queue-worker CPU minus all exclusive buckets is separately unclassified worker,
transaction, activity, transport and observer work. SQL elapsed and task/timer
delay remain separate observations. The observer is disabled unless explicitly
collected in that process and is never captured by a retained frame. Its clock
calls and delegate wrappers add overhead; paired observer-on/off measurements
are required before treating these timings as representative.
This is process-wide attribution for the probe's ordinary sequential worker.
It does not establish independent per-Fiber CPU accounting when unrelated
application Fibers or callbacks overlap an observed execution.

Set `DWF_GROWTH_OBSERVERS=off` for a paired control without the optional SQL,
task-delay, timer-lateness, executor CPU and cache-peak observers. Those unmeasured fields are
reported as `null`. The committed-completion barrier, source/lock checks,
authored-entry counters, process CPU/memory readings and final history/effect/
deadline audit and constant-space task-maintenance counters remain. This is a minimally observed control, not an uninstrumented
production benchmark. Use the same observer setting for both replay modes and
report it alongside their results.

For the current observer's overhead, select `observers=compare` with 100 events,
one worker and one run. Each hosted repetition keeps the same host, built image
and dependency lock for three observer blocks: on/off/on, reversed to off/on/off
for repetition two. Each block still runs both replay modes in fresh containers.
The repeated setting provides a drift control; compare CPU within the same
host and replay mode. Separately dispatched observer-on/off jobs on different
hosts do not establish observer overhead. Every original retains its normal
budget and audit, and a failed block does not suppress later originals.

The [current-main small growth comparison](results/issue-748-20261020/GROWTH.md)
covers twelve fresh 10/100-event originals after the released payload-read and
entry-binding changes. It records per-pair host/image identities and preserves
the distinction between whole-worker CPU and isolated replay attribution.

The [October 18 observer comparison](results/issue-748-20261018/OBSERVER-OVERHEAD.md)
contains thirteen fresh 100-event cells on current prepared source, including
three pairs per execution mode and a final drift control. All originals pass.
Paired listener CPU changes are smaller than the observed baseline drift, so a
positive observer cost remains unresolved. The report preserves the exact input
lock, actual cache counters and SQL/claim/timer diagnostics. Its conclusions apply
to the measured topology; larger histories, concurrency and published consumers
remain separate acceptance requirements.

The [executor CPU and drift report](results/issue-748-20261020/EXECUTOR-CPU.md)
records eighteen new same-host originals for the current clock/listener observer.
It separates actual authoring, initial loading, validation, dispatch and the
history/projection role, with per-host overhead and repeated-setting drift.

## Installed-package acceptance

After publication, repeat the continuation, cache, process-loss and execution
profile controls in a fresh Composer project requiring the exact released
Workflow tag. Use the Testbench/PHPUnit versions from the declared input lock.
Copy the runner's `tests/Fixtures`, `tests/Support`, `tests/bootstrap.php`,
`tests/TestSuiteSubscriber.php`, `phpunit.xml` and these four test files into
that project, preserving their relative paths:

```text
tests/Unit/V2/EmbeddedContinuationTest.php
tests/Unit/V2/EmbeddedContinuationProcessTest.php
tests/Unit/V2/WorkflowContinuationCacheTest.php
tests/Unit/V2/WorkflowExecutionProfileTest.php
```

The consumer autoload maps `Tests` only to its copied fixtures; all `Workflow`
classes and functions must load from its normally installed package. Check
the installed version/reference and every `src`/`resources` byte against the
immutable release before running `vendor/bin/phpunit` on those four files.
The process-loss fixtures launch children from that same consumer directory.
Retain the actual consumer lock, PHP/framework/tool versions, loaded-file
provenance and outcomes in the owning release record. A mirrored source package
can check the runner before publication but is not published-package evidence.

With observers enabled, `history_selects` counts SELECT statements mentioning
the actual history table, and `history_select_elapsed_ms` sums their reported SQL
duration. These are separate from all SQL statements and elapsed SQL time. They
do not measure history decoding, PHP projection CPU or database CPU.

`setup` selects only 10-event functional cohorts, with one/eight runs, one/four
workers and both replay modes. Their predeclared observation bounds are 30 seconds
for one run and 120 seconds for eight runs. They may run on shared development
hardware; their timings must not be used as comparative performance evidence.

```sh
PROFILE_EMBEDDED_CONTINUATION=setup \
  vendor/bin/phpunit tests/Performance/EmbeddedContinuationGrowthProfileTest.php \
  --cache-directory /tmp/continuation-profile-parent --testdox --fail-on-warning
```

`measure` selects all three history sizes, worker/run combinations, both modes
and three fresh repetitions. Each original has a 3,600-second run/execution and
observation budget. Startup has its own 30-second bound and graceful drain has
15 seconds. No failed original is resumed or rebudgeted by this probe.
Ordinary transport redispatch is observed within the original budget; a lease
recovery or error repair still fails the audit.
Failure remains a failure, with unfinished work and bounded diagnostic tails.

Run **one selected cell per fresh container** on an isolated machine. Otherwise
the cgroup memory peak can include an earlier cell. Use the same prepared PHP image
and dependency lock, with actual `pcntl` timeout/signal support. The probe refuses
measurement mode without that support or the declared cgroup v2 limits: one CPU,
1 GiB aggregate memory and zero swap, including controller and all workers. The
kernel must expose the cgroup aggregate peak-memory counter; its absence refuses
measurement rather than substituting a sum of process peaks.
Capture machine/kernel/CPU/storage facts and competing workload before admission.

The pinned Composer/PHP image can be prepared with the standard PHP extension
installer. Install dependencies before the measured container starts, then keep
that lock and vendor tree unchanged across the comparison:

```sh
docker build --tag dw-continuation-profile:local tests/Performance/continuation
export DWF_PROFILE_IMAGE=dw-continuation-profile:local
cp tests/Performance/continuation/COMPOSER-INPUT.lock composer.lock
export COMPOSER_ROOT_VERSION=$(jq -r '.extra["durable-workflow"]["product-train"]' composer.json)
docker run --rm --user "$(id -u):$(id -g)" \
  --env COMPOSER_HOME=/tmp/composer --env COMPOSER_ROOT_VERSION \
  --volume "$PWD:/app" --workdir /app "$DWF_PROFILE_IMAGE" \
  composer install --prefer-dist --no-interaction --no-progress
```

```sh
docker run --rm --user "$(id -u):$(id -g)" \
  --network none \
  --cpus 1 --memory 1g --memory-swap 1g \
  --env PROFILE_EMBEDDED_CONTINUATION=measure \
  --volume "$PWD:/app" --workdir /app "$DWF_PROFILE_IMAGE" \
  php vendor/bin/phpunit tests/Performance/EmbeddedContinuationGrowthProfileTest.php \
  --filter '100-events_1-workers_1-runs_retained_repeat-1' \
  --cache-directory /tmp/continuation-profile-parent --testdox --fail-on-warning
```

Alternate replay/retained pair order between repetitions, as the data provider
does, to reduce ordering bias. Repeat each declared cell, preserving failed and
unfinished outcomes in the readable report. Do not dispatch the complete matrix
before small-cell cost/runtime observations justify its bounded admission. Review
the `CONTINUATION_GROWTH_REPORT` JSON records, publish a compact outcome table and
remove disposable databases, dependencies, samples and transport afterward. Full
histories and effect rows are validated in place, not exported as bulk archives.

## Hosted candidate comparison

The existing `embedded-replay-profile.yml` workflow also accepts
`execution=continuation`. Its default remains the separately locked published
cold-bridge probe. Select one history size and worker/run topology per dispatch:

```sh
gh workflow run embedded-replay-profile.yml --ref YOUR_CANDIDATE_REF \
  -f execution=continuation -f events=10 -f workers=1 -f runs=1 -f observers=on
```

By default, three hosted jobs each run one replay/retained pair, alternating pair
order for repetition two. Use `-f repetitions=1` for one bounded pilot before
admitting all three repetitions. A single pilot does not complete comparative
acceptance. Each original gets a fresh constrained container and database.
The two modes within a pair share the same host, source, image and input lock.
Read and compare the recorded host/dependency identities across repetitions;
hosted hardware may vary. Do not treat incomparable hosts as matched measurements.

`scripts/profile-embedded-continuation.sh EVENTS WORKERS RUNS REPETITION` runs
that same pair locally after the preparation above. It verifies clean tracked
source and the exact input lock, records each container's exit/OOM/restart state,
and removes only its own containers. It preserves a failed first mode while
running the second independent original. The outer transport timeout does not
change either original's 3,600-second workflow/observation bounds.

Complete the small functional/source controls before admitting a 10-event pair,
then assess its runtime and cost before larger or concurrent cells. Dispatching
all twelve size/topology combinations produces the declared 72 originals.
Scalar results remain in ordinary Actions logs; disposable dependencies are
removed and no history/database archive is uploaded. Source comparison does not
replace published-package or affected-consumer qualification.
