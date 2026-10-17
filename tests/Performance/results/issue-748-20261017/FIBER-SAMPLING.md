# Cold replay sampling, October 17, 2026

Related to [#748](https://github.com/durable-workflow/workflow/issues/748).
This diagnostic selects the next cold-path investigation. It does not add a
live execution cache, qualify a warm profile or establish production capacity.

## Qualify Fiber attribution first

The committed `benchmarks/embedded-replay/fiber-profiler-control.php` performs
the same short arithmetic work before and after a Fiber suspension. The heavy
case adds a separate 20-million-iteration inline caller loop while suspended.
All original checksums must match. Each case ran three times on PHP 8.5.6,
with one CPU, 256 MiB memory and no swap.

| Profiler | Plain authored self CPU | Heavy-caller authored self CPU | Decision |
| --- | ---: | ---: | --- |
| XHProf, published tag 2.3.10 | 6.822–7.138 ms | 469.142–553.451 ms | Reject for this attribution |
| PHP-SPX 0.4.22 | 7.1–7.2 ms | 468.2–472.8 ms | Reject for this attribution |

Both charge caller work to the suspended function. These results apply to
the tested sources and configuration, not every version of either tool.
XHProf's official flat-info helper computed its self CPU from original raw
metrics. Its [2.3.10 source](https://github.com/longxinH/xhprof/tree/da774867b337de4a1caeb5dfdd6a3f6793f73391)
declares extension version 2.3.9; both identities were checked. SPX source is
[625fa854](https://github.com/NoiseByNorthwest/php-spx/tree/625fa8542415ff9cd3380432989b1cd139e1d346),
with CPU metrics, builtins enabled and automatic start disabled.

The separate [phpspy 73a14dc2](https://github.com/adsr/phpspy/tree/73a14dc24588557c850df04da157d334a1d187d2)
sampling control instead records 7/7/7 authored leaf samples in the plain
cases, and 6/5/6 authored plus 241/237/238 caller leaf samples in the heavy
cases. Setup-sleep samples are excluded. This distinguishes active caller
work from suspended authored work; sample counts are not exact CPU times.

## Published cohort and controls

Product code is the normal Composer installation of Workflow **2.5.30**,
source `ccc3b04b5b9f80ccb6a38245a98d88e2f1b50563`. All 937 source/resource
files match the release and reflection resolves the installed classes.
PHP 8.5.6, Laravel 13.37.0, Testbench 11.3.0 and Avro 1.12.2 are unchanged
from the separate locked 2.5.28 consumer. Only Workflow's package record and
the root content hash change; every other lock object/field is identical.
The committed 2.5.28 baseline was not changed.

The container has one CPU, 1 GiB memory and no swap, including the observer.
The host is shared. The probe uses SQLite, fake queues, mocked activity effects
and virtual workflow time. It is not a real-queue or timer-latency experiment.
Observed probe implementation is `59e5c5cc5976295416688a8b52f77fb3610a621c`;
the unobserved baseline is `15b220371db7a00700ddc3923912259d0636f39f`.
The difference adds optional physical observation windows outside the measured
CPU span, with no product runtime change.

The default nine-replay probe passes **1 test / 2,114 assertions**. The separate
ordinary-only observed probe passes **1 test / 206 assertions**. Each original
has 102 events, 27 activity effects and 55 completed first-attempt tasks with
zero repairs. Stored and input histories remain unchanged. Every cold step
has zero SQL, one authored workflow entry, nine round visits, result 27 and
one identical workflow-completion command within its cohort.

## Selected replay windows

| Repetition | Unobserved step CPU | Observed step CPU | Complete selected stacks | `json_decode` leaves |
| --- | ---: | ---: | ---: | ---: |
| 1 | 506.175 ms | 515.036 ms | 114 | 69 |
| 2 | 506.836 ms | 516.827 ms | 112 | 77 |
| 3 | 506.529 ms | 513.769 ms | 112 | 69 |

The observed median is 1.7% higher in this cell. This is an overhead observation,
not a correction factor or an estimate of uninstrumented function CPU. The
observer also adds substantial whole-test wall time.

All **215 of 338** selected `json_decode` leaf samples pass through Eloquent's
`fromJson` path. Their nearest workflow caller is:

| Workflow caller | JSON leaf samples |
| --- | ---: |
| `WorkflowStepHistory::isLocalActivityEvent` | 70 |
| `ParallelChildGroup::metadataPathForSequence` closure | 40 |
| `WorkflowStepHistory::detailMismatchForSequence` | 35 |
| `WorkflowStepHistory::conflictingEventTypesForSequence` | 33 |
| `WorkflowStepHistory::workflowStepEventTypesForSequence` | 31 |
| `ActivityReplayIdentity::assertCall` | 6 |

These are sampled leaves, not call counts. No authored `handle` leaf was
sampled in the selected windows; this does not prove zero authored CPU.

The final capture uses phpspy's brief process-pause option, only on the isolated
test child. This option must not be transferred to a live service. PID and
physical start/end timestamps select each measured step, including stacks
inside its Fiber. Two records outside those windows are excluded. All selected
frames are contiguous, with no truncated records or memory-read errors.
The requested rate is 199 Hz, not a claim that every scheduled sample arrived.
The whole capture reports 2,308 late intervals and two process-exit `ptrace`
refusals. A per-window missed-sample count is unavailable.

Earlier attachment and child-selection attempts produced no usable profile.
An unlocked capture produced memory-read errors and its distribution is not
used. Their functional passes do not qualify those observers.

## Reproduction and next action

Use a separate normal installation of the published cohort above. In the
existing bridge command add `PROFILE_REPLAY_ORDINARY_ONLY=1` and
`PROFILE_REPLAY_SAMPLE_WINDOWS=1`. The default remains all three modes, with
no sample-window fields. The observer must be an ancestor of the PHP test
child when using ordinary Linux descendant-access permissions. On this image
the child executable is `/usr/local/bin/php`; matching only `php` selected
the waiting PHPUnit parent instead.

The tested phpspy options are:

```sh
phpspy -V85 -H199 -S -b65536 -T1 -i300000 \
  -P '-f "^/usr/local/bin/php( |$)"' \
  -f 'WorkflowFiberRunner::step|ReplayGrowthWorkflow::handle' -dpt \
  -o '/tmp/replay.%d.traces'
```

Retain only complete records whose PID and timestamp match one emitted replay
window. Include the authored Fiber in the filter rather than assuming every
Fiber stack retains the executor frame. Whole-process setup samples, partial
reads and another process's frames cannot establish replay attribution.

The next investigation is repeated ordinary-model decoding during history
classification and matching, especially local-activity and parallel-group
checks. Preserve mutable-model behavior, event-shape validation and committed
history authority when evaluating a change. The original warm-execution
identity, bounds, invalidation, feature-fallback and concurrency acceptance
remain open; this profile does not establish an exact authored CPU fraction.
