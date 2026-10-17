# Initial embedded continuation growth measurements

These are source measurements for [#748](https://github.com/durable-workflow/workflow/issues/748).
They cover 10 and 100 events with one worker and one concurrent run. The larger
histories, concurrent and multiworker measurements, listener-overhead controls
and published-package consumers remain incomplete. No production saving or
stable warm-execution release is established.

## Source and conditions

- Source: `b2c2ad3e096dad74ba892303fb9fcb11e025852e`, whole tree
  `ddfb3c1c7c03f0e17500d611b1ff583fd6f25cd4`. Product runtime/config/resources
  are identical to `edb705df`.
- Dependency lock SHA-256:
  `1095bc931ee44f4d445c4321b5b31dd9bf27ba02a828b2598296272837e93c79`.
  All 950 checked source/resource/fixture files match before admission.
- PHP 8.5.6, Laravel 13.37.0, SQLite 3.51.2 and actual pcntl support.
  Pinned tooling image root filesystem has 15 verified layers. Imported image
  identity: `6160add78a3e98b07e84d4c243cd5419e45a5a3b30b3e254fe296a40c62e04f8`.
- Isolated x86_64 Linux 6.8.0-146-generic guest, two logical CPUs on a reported
  Intel Xeon Skylake virtual CPU, Docker 29.1.3 and cgroup v2.
- One fresh container per cell: one CPU, 1,073,741,824 bytes aggregate memory,
  zero swap and no network. Kernel aggregate peak memory is available. There
  are no concurrent application/build containers during measured cells.
  Guest-wide CPU steal during the 100-event cells is 0.006–0.107% of reported
  CPU time; this does not establish physical-host exclusivity.
- Three fresh repetitions per size and mode. Pair order is replay/retained,
  retained/replay, replay/retained. Both modes have the original 3,600-second
  workflow/execution/observation bounds, 30-second startup and 15-second drain.
- Real database queue, typed activity effects and one-second timers. Cache
  bounds stay at four entries, a nonrenewing 30-second age and a 128 MiB worker
  RSS threshold. Handoff and cache reuse are not forced.

The separate guest functional setup passes eight cohorts / 6,150 assertions.
Its timing is excluded from the comparison. All twelve measured cells pass:
six originals at each size, 660 complete events, 120 activity effects, 516
completed first-attempt tasks and 132 timers in total. Every original preserves
its admission prefix, full typed result, recorded clocks and original deadlines.
There are zero repairs, errors, failed jobs or unfinished runs. All measured
containers exit zero without OOM.

## Results

CPU and wall values below are medians of three independent cells. Memory values
are the range of the complete fresh-container peak, including test startup and
audit; they do not isolate retained-frame bytes. Worker CPU includes executor,
history, projection, dispatch and activity/timer work.

| Events | Mode | Worker CPU seconds | Queue wall seconds | Container peak MiB | Workflow entries | Round visits |
| --- | --- | ---: | ---: | --- | ---: | ---: |
| 10 | Replay | 1.311 | 7.498 | 143.95–144.04 | 4 | 4 |
| 10 | Retained | 1.317 | 7.500 | 144.04–144.27 | 1 | 1 |
| 100 | Replay | 51.055 | 85.428 | 155.13–155.50 | 40 | 418 |
| 100 | Retained | 44.750 | 80.223 | 155.16–155.50 | 3 | 46 |

At ten events the CPU and wall ranges overlap. At 100 events, retained worker CPU
is 12.3% lower and wall time 6.1% lower in this instrumented workload. Retention
still performs three cold workflow entries and 37 hits: ordinary age eviction
or publication expiry prevents extending a frame's original 30-second lifetime.
These results do not isolate replay CPU or the listeners' cost.

At 100 events, worker CPU ranges are 51.010–51.353 seconds for replay and
44.747–45.534 seconds for retention. Time to the test worker's ready barrier is
0.406–0.458 seconds, measured separately from queue execution. This includes
PHPUnit/Testbench boot, source checks and opening SQLite. It is not a stock
application's Artisan startup measurement.

## SQL, memory and scheduling

All-SQL statement counts are 858 for ten-event replay and 865 for retention.
At 100 events they are 11,223–11,241 and 11,287–11,309 respectively. These counts
include ordinary queue and maintenance reads, and are not history-only counts.
The later probe adds separate history SELECT counts; that data cannot be
backfilled into these originals.

Each 100-event cell has 79 task claims and 20 timer firings. Per-cell claim-delay
p50 ranges are 342–384 ms for replay and 372–376 ms for retention; p95 ranges
are 1,293–1,473 ms and 1,391–1,392 ms. Timer-lateness p50 ranges are 1,029–1,334 ms
and 1,227–1,248 ms; p95 ranges are 1,848–1,903 ms and 1,766–1,901 ms. These are
ranges of individual-cell percentiles, not pooled distributions. Timer and queue
delay are included in wall time and remain distinct from CPU.

Worker peak RSS at 100 events is about 89.6–89.8 MiB for replay and 90.1–90.2 MiB
for retention. Actual worker cache entry counts return to zero at shutdown.
SQL duration measures elapsed client calls, not database CPU; process peaks
are not simultaneous aggregate memory.

## Listener-overhead control and unfinished work

The following harness preparation introduces `DWF_GROWTH_OBSERVERS=off`, keeping
the same product runtime and durable correctness checks. It removes optional
SQL, queue-delay, timer-lateness and cache-peak listeners and reports their
unmeasured fields as null. Completion barriers, authored counters, CPU/memory
readings and the final history/effect/deadline audit remain.

The initial local four-cell control is **failed**: three cells pass, while the
retained four-worker/eight-run cell observes a repaired task and fails the
unchanged zero-repair assertion. A separate diagnostic cohort passes but does
not reproduce or explain that original failure. No repair/deadline policy is
relaxed. The new bounded failure report captures task state before disposable
databases are removed. This is incomplete qualification, not a product-cause claim.

The final minimally observed local component passes four fresh setup cohorts /
5,449 assertions, including the four-worker/eight-run pair. Its new history-only
SELECT fields and optional metrics are checked independently of the preserved
failed original. Hosted and isolated overhead measurements remain required.
The observer-enabled local pair passes two tests / 632 assertions, including
positive history SELECT counts bounded by all-SQL counts. Style and focused
static analysis pass. Later failure-only progress diagnostics remain separate
from these successful component executions.

The next acceptance still includes 1,000-event and concurrent/multiworker cells,
actual hit/miss/capacity behavior under the aggregate limit, minimally observed
paired measurements, remaining lifetime/failure cases and published consumers.
All original #748 acceptance boxes remain open and #834 remains draft.
