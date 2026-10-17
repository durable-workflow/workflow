# Embedded job-handler profile, October 17, 2026

Related to [#748](https://github.com/durable-workflow/workflow/issues/748).
The [cold bridge sample](FIBER-SAMPLING.md) is a different execution path.
This measurement exercises the actual embedded job handlers before choosing
where retained execution could help.

## Cohort and measured operation

Workflow **2.5.30**, source `ccc3b04b5b9f80ccb6a38245a98d88e2f1b50563`,
loads from a normal Composer installation. All 937 source/resource files and
the loaded executor/job paths match. PHP 8.5.6, Laravel 13.37.0, Testbench
11.3.0 and Avro 1.12.2 are locked. Only the Workflow package record and root
content hash differ from the separate committed 2.5.28 consumer; that consumer
and its prior results remain unchanged.

Probe component `4aa7ee99132b04e169136d91357ead6a57f36219` adds optional
physical windows, SQL, prefix visits and memory diagnostics to the existing
role probe. Its default mode separately passes **1 test / 156 assertions**
without the new output fields. Unobserved and final observed modes each pass
**3 tests / 474 assertions**, with three independent originals per mode.

Every original has eight rounds, 91 history events, 24 mocked activity effects,
result 24, 49 completed first-attempt tasks and zero repairs. It starts the
authored workflow 17 times and visits the round body 80 times. There are 124
`projectRun` calls and 24 `recordActivityStarted` calls per original.

The fake dispatcher invokes real job handlers through Laravel's container,
including `RunWorkflowTask::handle` and `WorkflowExecutor::run`. It advances
virtual time between ready-task batches. It does not measure queue transport,
worker replacement, real timer latency or a retained execution.

Instance reservation, application/migration startup, observer setup and final
audit queries are outside the measured start-and-ready-task window. The host
is shared. The container has one CPU, 1 GiB memory, no swap and no network,
including the observer. No OOM or container restart occurred.

## Ordinary process CPU and SQL

The existing role decorator delegates to the released default implementation.
It measures process CPU and avoids double-counting nested role calls. It adds
instrumentation overhead. The activity-start method includes history recording
and projection; neither column is a pure serializer or pure replay measurement.

| Repetition | Whole process CPU | `projectRun` CPU | Activity-start role CPU | SQL statements | SQL elapsed | Peak RSS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 20.488316 s | 12.425097 s | 2.274531 s | 7,178 | 3.178870 s | 80,480 KiB |
| 2 | 20.320855 s | 12.359675 s | 2.280332 s | 7,178 | 3.059350 s | 80,472 KiB |
| 3 | 20.575303 s | 12.490568 s | 2.326184 s | 7,178 | 3.153270 s | 80,716 KiB |

The two role paths account for **71.9%** of aggregate measured process CPU.
They overlap the executor's call tree and must not be added to its samples.
The remainder includes claiming, dispatch, persistence and other work; it is
not an exact replay CPU fraction or a predicted cache saving.

SQL time comes from Laravel's query events. It is elapsed time, excludes
transaction commits and is not database CPU. SQLite runs in the PHP process.
Do not subtract SQL elapsed time from process CPU. Peak RSS includes framework
startup and the complete workload; it does not measure one retained entry.
Peak PHP-allocated memory is 57,147,392 bytes in each ordinary original.

## Independently sampled originals

The exact phpspy source/binary from the prior tool qualification is reproduced:
source `73a14dc24588557c850df04da157d334a1d187d2`, binary SHA-256
`997b586b640ee804948b4e88c72ce72fa1bf614aee8ee1df117684ee03f8c2a1`.
It samples only its descendant test children, with brief pauses, requested
99 Hz and a 65,536-byte output buffer. This pause mode is for the isolated
test, not a live service. Three seconds of setup precede measured admission.

| Repetition | Observed process CPU | Complete in-window stacks | Pre-window coverage | SQL statements |
| --- | ---: | ---: | ---: | ---: |
| 1 | 21.940797 s | 3,468 | 298 stacks | 7,178 |
| 2 | 21.771300 s | 3,434 | 296 stacks | 7,178 |
| 3 | 22.088842 s | 3,486 | 298 stacks | 7,178 |

The observed median process CPU is 7.1% higher in this cell. That is an
overhead observation, not a correction factor. SQL elapsed time rises to
4.954–5.002 s under observation, including sampling pauses during statements.

All **10,388** selected records have contiguous frames and the correct PID and
physical window. There are no memory-read errors or truncated records. The
whole capture reports 4,085 late sampling intervals and five process-exit
`ptrace` refusals; per-window missed counts are unavailable. There are 894
records outside the measured windows, which are excluded.

Visible-frame buckets are mutually exclusive:

| Stack scope | Samples |
| --- | ---: |
| History/projection role outside the executor | 4,930 |
| History/projection role inside the executor | 571 |
| Executor without the role | 1,329 |
| Other job handling, persistence and framework work | 3,558 |

These are **wall-time stack samples**, not CPU shares. For example, 1,827 leaves
are in `PDO::commit` and 1,104 in `PDOStatement::execute`, which can include I/O
waiting. Other frequent leaves are `json_decode` (1,639) and
`DateTime::__construct` (737). The bridge's JSON-heavy sample distribution
cannot stand in for this embedded workload.

The first collector used a per-thread `%d` filename. The tool reopens that
name with truncation for each new target, so only the last process's trace
survived. Its three functional originals passed, but aggregate observation was
rejected. The final three originals above are fresh; a single shared output
file preserves all targets. Original budgets, runtime, fixtures and assertions
were not relaxed. Raw traces are disposable after review.

Use `PROFILE_EMBEDDED_SAMPLE_WINDOWS=1` with the existing role-probe command.
As in the bridge qualification, the observer must be an ancestor of the test
children. The final observer options are:

```sh
phpspy -V85 -H99 -S -b65536 -T1 -i600000 \
  -P '-f "^/usr/local/bin/php( |$)"' \
  -f 'ProjectionRoleProbeTest::testProjectionRoleCost|ReplayGrowthWorkflow::handle|WorkflowExecution::' \
  -dpt -o /tmp/embedded.traces
```

The shared output name deliberately has no `%d`. Require pre-window coverage
for each of the three original PIDs and select complete timestamped records
inside that PID's emitted measurement window.

## Design consequence

Retained execution must target the actual embedded executor. It cannot retain
the queue job or the database transaction. Both `RunWorkflowTask` and the
default task bridge wrap execution in transactions; workflow objects also bind
once to their run and Laravel container.

The next prototype must separate its in-memory execution frame from task
authority, publish reusable state only after root commit, and discard it after
rollback or uncertain acknowledgement. It must verify backend/namespace/run/
build identity and committed history before resuming, with immediate cold
fallback. Entry count, monotonic age and current worker memory need hard bounds;
the peak RSS above cannot establish those bounds or an entry's retained size.
Eviction must preserve #480's durable-cleanup semantics.

History/projection costs remain a separate component. The prototype must still
prove tenant/DI isolation, supported features and cold fallbacks, process loss,
real queues, hit/miss-heavy concurrency and the one-second continuation cases.
The original warm-execution acceptance remains open. No cache implementation,
package release, native adoption, production capacity or speedup is established.
