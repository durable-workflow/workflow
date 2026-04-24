# Workflow V2 Mutable-Side-Effect Policy Contract

This document freezes the v2 contract for what mutating and
non-deterministic operations workflow authoring code is allowed to
perform, what must be wrapped in the durable `sideEffect(...)`
primitive, what must be pushed out to an activity, and what the
engine detects and reports back when authors break the rules.

The guarantees below apply to the `durable-workflow/workflow` package
at v2 and to every host that embeds it or talks to it over the worker
protocol. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1). Phase 1 names
workflow authoring code as deterministic under replay and lists the
broad prohibited categories; this document extends that language into
the full mutable-side-effect contract: what counts as a mutable side
effect, which durable primitives convert a mutable source into a
replay-safe value, what the engine detects statically and at boot,
what the diagnostic surface exposes, and what will never become a
product feature.

## Scope

The contract covers:

- **workflow authoring code** — the body of a method that runs
  inside a workflow fiber under replay. This is the code that
  `WorkflowExecutor` re-runs every decision batch.
- **mutable side effects** — any operation that reads from, writes
  to, or observes state outside the workflow's own history and
  durable state tables. Wall-clock reads, randomness, ambient
  request or authentication context, direct database access,
  cache access, filesystem IO, and HTTP calls are all examples.
- **replay-safe conversion primitives** — the v2 operations that
  convert an otherwise-mutable source into a deterministic,
  replay-safe value. The canonical primitives are `sideEffect()`,
  `uuid4()`, `uuid7()`, `now()`, durable timers, activities,
  signals, updates, and workflow input.
- **detection and diagnostics** — the boot-time guardrail and
  run-time diagnostic surfaces that flag workflow classes whose
  source contains mutable side-effect calls.
- **non-goals** — the explicit list of things v2 will not do,
  such as introducing an ambient "mutable mode" or silently
  sandboxing non-deterministic calls at run time.

It does not cover:

- activity implementations. Activity bodies run once, outside
  replay, and are free to perform mutable side effects. Activity
  authorship is covered by the activity-execution-model docs.
- signal, update, and query handler bodies that are not authoring
  code. Signal and update handlers run inside the executor but
  their per-event semantics come from the Phase 1 contract; this
  contract bounds what those handlers can call.
- host-wide observability (logging, tracing) performed outside
  the workflow fiber by the Laravel host, queue worker, or a
  service provider. Those are infrastructure paths that do not
  enter the workflow history.

## Terminology

- **Authoring code** — PHP code executed inside the workflow
  fiber by `WorkflowExecutor` or replayed by
  `QueryStateReplayer`. Authoring code yields commands
  (`ActivityCall`, `TimerCall`, `SideEffectCall`, `VersionCall`,
  etc.) through `WorkflowFiberContext::suspend()`.
- **Mutable side effect** — a call that reads or writes state
  whose value cannot be reproduced from history alone. Wall-clock
  time, randomness, ambient request context, direct database
  queries, filesystem IO, and HTTP responses are all mutable
  side effects.
- **Replay-safe** — a value or operation whose observed result
  on replay equals its observed result on the first invocation.
  History events are replay-safe by construction; bare mutable
  reads are not.
- **Durable primitive** — an engine-provided operation that
  converts a mutable source into a replay-safe value by
  recording the observed value in history. `sideEffect()` is
  the canonical example; activities, durable timers, and
  signals are the other durable boundary surfaces.
- **Determinism diagnostic** — a static-analysis finding
  produced by
  `Workflow\V2\Support\WorkflowDeterminismDiagnostics` that
  names a specific mutable-side-effect rule and the symbol that
  tripped it.

## The policy

v2 workflow authoring code MUST be deterministic under replay.
Specifically:

1. Authoring code MUST NOT read wall-clock time directly. Use
   `Workflow\V2\now()` or `Workflow::now()`, which return the
   deterministic workflow time recorded by the executor.
2. Authoring code MUST NOT read randomness directly. Use
   `Workflow\V2\uuid4()`, `Workflow\V2\uuid7()`, or wrap the
   random source in `Workflow\V2\sideEffect()`.
3. Authoring code MUST NOT read ambient request or
   authentication context. Pass the required data in through
   workflow input, a signal payload, or an update payload.
4. Authoring code MUST NOT call the Laravel `DB`, `Database`,
   `Cache`, `Auth`, or `Http` facades directly. Move the
   IO to an activity, or snapshot a derived value with
   `sideEffect()` if the source is already deterministic.
5. Authoring code MUST NOT perform filesystem IO. Move it to
   an activity.
6. Authoring code MUST NOT mutate external state through any
   path. Every mutation is an activity.

The contract is asymmetric: READS that are deterministic under
replay are permitted (for example, reading `$this->run` or
computing a pure function of workflow state); WRITES or
non-deterministic reads are never permitted without an explicit
durable boundary.

## Replay-safe conversion primitives

The v2 API provides the following durable primitives that convert
a mutable source into a replay-safe value. Each one records a
typed history event so replay reads the recorded value instead
of re-invoking the source.

### `sideEffect(callable)`

`Workflow\V2\sideEffect()` is the general-purpose snapshot
primitive:

- The callable is invoked ONCE on first execution of the current
  decision batch, and its return value is recorded in a
  `SideEffectRecorded` history event with the current `sequence`
  and a codec-serialised `result` payload.
- On replay, the callable is NOT invoked; `QueryStateReplayer`
  and `WorkflowExecutor` read the recorded value back.
- The callable MUST return a serialisable value. Non-serialisable
  values produce a serialisation error at commit time.
- The callable MUST NOT schedule other workflow commands,
  start activities, or yield further `YieldedCommand` instances.
- `SideEffectRecorded` is exactly-once at the durable state
  layer for a given `(run_id, sequence)` by the Phase 1 contract.

### `uuid4()` / `uuid7()`

`Workflow\V2\uuid4()` and `Workflow\V2\uuid7()` are thin wrappers
around `sideEffect()`:

- `uuid4()` records a freshly-generated UUIDv4 via
  `DeterministicUuid::uuid4()`.
- `uuid7()` records a time-sortable UUIDv7 derived from
  `now()` (the deterministic workflow time) via
  `DeterministicUuid::uuid7($time)`. Because it reads `now()`
  first, the UUID is stable across replays even though its
  time component is not wall-clock time.

### `now()`

`Workflow\V2\now()` returns the deterministic workflow time
through `WorkflowFiberContext::getTime()`:

- Inside a workflow fiber, `now()` returns the timestamp of the
  last history event the executor replayed before resuming the
  fiber. This timestamp is recorded in history, so it is
  stable across replays.
- Outside a workflow fiber (for example in an activity body or
  a test harness), `now()` falls back to wall-clock time. That
  fallback is correct because no replay is running.
- `Workflow::now()` is a static delegate to the namespaced
  helper.

### Durable timers

`Workflow\V2\timer($duration)` suspends the workflow until a
`TimerFired` event arrives for the current sequence. Timers are
the durable equivalent of "sleep for N seconds". Authoring code
MUST NOT emulate sleep through loops or wall-clock polling.

### Activities

Any external IO (HTTP, database writes, message-bus publish,
filesystem) MUST be an activity. Activities run outside the
workflow fiber, record `ActivityStarted` and one of
`ActivityCompleted` / `ActivityFailed` / `ActivityCancelled`,
and return their result through history. Replay reads the
recorded outcome.

### Signals, updates, and workflow input

Data that enters the workflow from the outside world enters
through `start`/`startWithArguments` (workflow input), `signal`
(fire-and-forget one-way), or `update` (two-way synchronous
request/response). All three surfaces record their payload in
history, so authoring code that reads them is replay-safe.

## Forbidden patterns and diagnostic rules

`Workflow\V2\Support\WorkflowDeterminismDiagnostics` is the
single canonical lister of forbidden authoring patterns. The
rules are:

| Rule code | Trigger | Message gist |
|---|---|---|
| `workflow_wall_clock_call` | `date`, `gmdate`, `hrtime`, `microtime`, `now`, `time`, or any `Carbon`/`Date`/`DateTime`/`DateTimeImmutable`::`now`/`today`/`tomorrow`/`yesterday` static call | "Workflow code reads wall-clock time. Pass time in as input, use a durable timer, or snapshot the value with sideEffect()." |
| `workflow_random_call` | `random_bytes`, `random_int`, `rand`, `mt_rand`, `uniqid`, or any `Str`::`orderedUuid`/`password`/`random`/`uuid`/`ulid` static call | "Workflow code reads randomness. Pass the value in as input or snapshot it with sideEffect()." |
| `workflow_ambient_context_call` | Laravel `auth()` or `request()` helpers | "Workflow code reads request or authentication context. Pass that data in as workflow input or through a durable command payload." |
| `workflow_database_facade_call` | `DB::*` / `Database::*` static calls | "Workflow code reads or writes the live database. Move this work to an activity or snapshot the result with sideEffect()." |
| `workflow_cache_facade_call` | `Cache::*` static calls | "Workflow code reads or writes cache state. Use signals, updates, workflow input, or an activity result as the durable boundary." |
| `workflow_auth_facade_call` | `Auth::*` static calls | "Workflow code reads authentication state. Pass actor data through workflow input or durable command metadata." |
| `workflow_http_facade_call` | `Http::*` static calls | "Workflow code performs an HTTP call. Move external I/O to an activity so replay stays deterministic." |

Consumers MUST treat the `rule` string values as part of the
protocol. Automation that matches on `workflow_wall_clock_call`
to link findings to upgrade guides will break if a future change
renames the code. Adding new rule codes is allowed; renaming or
removing an existing code is a protocol break.

## Detection and diagnostics

### Static analysis

`WorkflowDeterminismDiagnostics::forWorkflowClass(string $class)`
scans the tokenised source of a workflow class and returns a
structured result:

- `status` is one of `WorkflowDeterminismDiagnostics::STATUS_CLEAN`,
  `STATUS_WARNING`, or `STATUS_UNAVAILABLE` (the string values
  `'clean'`, `'warning'`, and `'unavailable'`).
- `source` is one of `SOURCE_DEFINITION_DRIFT` (`'definition_drift'`),
  `SOURCE_LIVE_DEFINITION` (`'live_definition'`), or
  `SOURCE_UNAVAILABLE` (`'unavailable'`). Drift indicates the
  run's recorded workflow-definition fingerprint does not match
  the class currently loaded in the worker process.
- `findings` is a list of individual findings, each carrying
  `rule`, `severity`, `symbol`, `message`, `file`, and `line`.

`WorkflowDeterminismDiagnostics::forRun(WorkflowRun $run)` is the
per-run entry point used by operator surfaces. It returns drift
first, then delegates to `forWorkflowClass` if the fingerprint
matches.

### Boot-time guardrail

`Workflow\V2\Support\WorkflowModeGuard::check()` runs the static
analysis over every registered workflow class at boot and acts
according to `workflows.v2.guardrails.boot`:

- `'warn'` (default) — each finding is logged at warning level
  with the workflow class, the type key, the message, the
  symbol, the file, and the line.
- `'silent'` — the guard returns immediately and performs no
  scanning. Used for environments where the diagnostic cost is
  not wanted.
- `'throw'` — the guard throws `LogicException` on the first
  finding. This is the CI-friendly mode; the thrown message
  identifies the rule, symbol, and location so the fix is
  actionable.

The guardrail is idempotent; calling it repeatedly does not
record history events or mutate durable state.

### Operator surfacing

`Workflow\V2\Support\RunDetailView` includes the determinism
diagnostic output per run, so Waterline's run detail can surface
"this workflow definition has N replay-safety warnings" without
additional query work. The CLI `dw run describe` surface MUST
render the same diagnostic blocks.

## Non-goals

The following are explicitly outside v2 and will not become
features without a separate roadmap issue and a protocol-level
change review:

- **Ambient mutable mode.** v2 will not offer a compatibility
  flag that disables determinism enforcement or lets workflows
  call mutable sources without a durable primitive. Drift-tolerant
  modes exist for rollout safety only (per the Phase 2 contract);
  they do not relax determinism.
- **Implicit capture.** v2 will not silently intercept wall-clock
  or randomness calls and convert them into `sideEffect()`
  records on the fly. Authors must invoke `sideEffect()`
  explicitly so the durable boundary is reviewable.
- **Opaque sandboxing.** v2 will not isolate workflow authoring
  code in a process or container that blocks IO at the OS level.
  The diagnostic is static and the contract is cooperative; the
  worker host is trusted.
- **A "maybe-deterministic" mode.** v2 does not accept findings
  as advisory. `STATUS_WARNING` is a warning today because the
  guardrail default is `'warn'`, but the long-term direction is
  to strengthen, not soften, the contract.
- **Retrofitted determinism.** A workflow that violates this
  policy and produces non-determinism in flight cannot be
  recovered by retroactively wrapping the source in
  `sideEffect()` — the history would mismatch. Authors must fix
  the code AND the workflow must continue-as-new or be
  explicitly abandoned so the new code starts cleanly.

## Consumers bound by this contract

The canonical consumers of this policy:

- `Workflow\V2\Support\WorkflowExecutor` — executes authoring
  code under replay. It does not trap mutable calls at runtime;
  it trusts the contract.
- `Workflow\V2\Support\QueryStateReplayer` — replays authoring
  code for queries with `setCommandDispatchEnabled(false)`.
- `Workflow\V2\Support\WorkflowDeterminismDiagnostics` — the
  static analyser that produces findings.
- `Workflow\V2\Support\WorkflowModeGuard` — the boot-time
  guardrail that surfaces findings.
- `Workflow\V2\Support\RunDetailView` — the per-run operator
  surface that renders diagnostics.
- `Workflow\V2\Support\DeterministicUuid` — the canonical
  replay-safe UUID provider used by `uuid4()` and `uuid7()`.
- `Workflow\V2\Support\WorkflowFiberContext` — the fiber-time
  and fiber-suspend surface; `getTime()` is the deterministic
  clock and `suspend()` is the command yield point.

Any new consumer that surfaces authoring-code findings MUST
route through `WorkflowDeterminismDiagnostics` rather than
implementing its own scanner. Renaming the rule codes or status
constants is a protocol-level change.

## History-event surface

The durable primitives named above produce the following typed
history events. These are the only events authoring code can
cause, and they are the complete set the contract relies on:

- `SideEffectRecorded` — produced by `sideEffect()`, `uuid4()`,
  and `uuid7()`.
- `VersionMarkerRecorded` — produced by `getVersion()`,
  `patched()`, and `deprecatePatch()`.
- `TimerScheduled` / `TimerFired` / `TimerCancelled` —
  produced by durable timers.
- `ActivityScheduled` / `ActivityStarted` / `ActivityCompleted` /
  `ActivityFailed` / `ActivityCancelled` / `ActivityRetryScheduled`
  — produced by activity calls.
- `ChildWorkflowScheduled` / `ChildRunStarted` /
  `ChildRunCompleted` / `ChildRunFailed` / `ChildRunCancelled` /
  `ChildRunTerminated` — produced by child workflow calls.
- `SearchAttributesUpserted` — produced by
  `upsertSearchAttributes()`.

Authoring code that appears to "do something" but does not
produce one of the events above has either produced nothing
durable (and is a bug) or has bypassed a durable boundary (and
is a policy violation).

## Test strategy alignment

- `tests/Unit/V2/WorkflowDeterminismDiagnosticsTest.php` pins
  the static-analysis rule set: the list of wall-clock
  functions, the list of random functions, the facade match
  list, and the severity/message/rule-code shape of the
  findings.
- `tests/Unit/V2/WorkflowModeGuardTest.php` pins the boot-time
  guardrail behaviour for each of the three modes (`warn`,
  `silent`, `throw`).
- `tests/Feature/V2/V2SideEffectWorkflowTest.php` exercises the
  durable-sidEffect primitive end-to-end.
- `tests/Feature/V2/V2DeterministicTimeTest.php` exercises the
  deterministic `now()` contract end-to-end.
- `tests/Unit/V2/WorkflowFiberContextTimeTest.php` pins the
  fiber-time contract used by `now()`.
- This document is pinned by
  `tests/Unit/V2/MutableSideEffectPolicyDocumentationTest.php`.
  A future change that renames, removes, or narrows any named
  guarantee (the six policy rules, the seven rule codes, the
  three status constants, the three source constants, the
  replay-safe primitives, or the non-goals list) must update
  the pinning test and this document in the same change so the
  contract does not drift silently.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Adding new replay-safe primitives or new detection rules is
compatible; renaming or removing existing ones is a breaking change
requiring explicit SDK coordination. Softening the policy — for
example promoting mutable-source calls from warning to silently
allowed — is a non-goal per the section above and must be rejected
by reviewers unless the change includes a corresponding roadmap
issue that explicitly relaxes the contract.
