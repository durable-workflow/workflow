# Workflow V2 Authoring and Definition Boundary Contract

## Scope

This contract defines the public authoring model for workflow and activity
definitions in the PHP v2 SDK, and the boundary future SDKs must share when
they target the same durable history catalog. It is a product contract, not an
implementation tutorial: changes here affect examples, generated docs, client
SDKs, worker adapters, and replay compatibility expectations.

The authoring model should stay small and inspectable. New helpers must
reinforce the durable-kernel mental model and expose clear replay, command, and
visibility semantics. A helper is not accepted if its behavior can only be
understood by reading executor internals.

## Primary authoring style

The primary style is straight-line workflow code using the namespaced helpers
from `Workflow\V2\functions.php`. Workflow code should read as normal PHP while
the helper names mark every durable boundary:

- `activity()` schedules an activity command and returns its durable result.
- `child()` schedules a child workflow command and returns its durable result.
- `timer()` records a durable timer wait.
- `await()` waits for a condition, signal, or timeout using replay-stable
  condition fingerprints.
- `now()` reads deterministic workflow time.
- `all()` and `parallel()` create a durable fan-out barrier.
- `sideEffect()`, `uuid4()`, and `uuid7()` record replay-safe values in
  history.
- `continueAsNew()`, `getVersion()`, `patched()`, `deprecatePatch()`,
  `upsertMemo()`, and `upsertSearchAttributes()` expose explicit lifecycle,
  versioning, and visibility commands.

These helpers are the vocabulary workflow authors should import first. New
helpers must be thin names for durable commands, deterministic reads, or
replay-safe markers.

## Static facade alternative

`Workflow\V2\Workflow` exposes a Temporal-style static facade as an import-style
alternative. `Workflow::activity()`, `Workflow::child()`, `Workflow::timer()`,
`Workflow::await()`, `Workflow::now()`, `Workflow::all()`,
`Workflow::parallel()`, `Workflow::sideEffect()`, and the rest of the facade
must delegate to the same semantics as the namespaced helpers.

The facade is not a second engine. It exists so authors can choose
`Workflow::timer('5 seconds')` instead of importing `timer()`. Documentation may
show either style, but examples should not imply that the two styles have
different replay behavior, command payloads, or visibility effects.

## Runtime primitives and remote handles

`WorkflowStub` is reserved for remote workflow handles and test harness control.
It may start, signal, query, update, describe, cancel, terminate, archive, load,
or fake workflows from outside runtime workflow code. It is not the authoring
primitive for activities, timers, awaits, side effects, version markers, child
starts, or concurrency inside a `Workflow::handle()` method.

Runtime workflow code should cross durable boundaries through the helper
functions or the `Workflow` static facade. This keeps definitions inspectable:
every yield point becomes a `Support\*` call value, a deterministic time read,
or an explicit visibility command.

## Concurrency boundary

Concurrency is expressed through `all()` and `parallel()`. Both names create the
same durable barrier and resolve results in iteration order. Authors do not need
fire-and-forget handles for normal fan-out. If a branch must survive the parent
closing, it should be modeled as a child workflow with an explicit parent-close
policy, not as an untracked local handle.

The barrier shape is part of replay compatibility. Replayers compare the
current fan-out topology with recorded parallel metadata, so helpers must not
hide extra branches or reorder existing branches.

## Replay-safety guardrails

Workflow-mode guardrails are diagnostics-first and fingerprint-aware. Static
checks flow through `WorkflowDeterminismDiagnostics`; boot/readiness enforcement
flows through `WorkflowModeGuard`; definition drift is compared with
`WorkflowDefinitionFingerprint`.

Workflow code must not read replay-unsafe ambient state directly. Database
queries, cache reads, wall-clock time, randomness, filesystem I/O, request
state, and external network calls belong in activities or in explicit
`sideEffect()` snapshots when the value itself must become history. Guardrails
should report actionable diagnostics before they block deployments, and they
must preserve enough fingerprint/source context to distinguish live-definition
findings from historical definition drift.

## History budget observation

Workflow code may observe its current history budget without reading storage
internals. `historyLength()` returns the current history event count,
`historySize()` returns the serialized history size estimate, and
`historyFanOut()` returns the largest parallel-group breadth recorded in this
run's history. `shouldContinueAsNew()` returns the continue-as-new suggestion
flag, which is true when any dimension reaches its hard threshold.

The budget is reported as a three-state pressure indicator:
`historyBudgetPressure()` returns `ok`, `approaching`, or
`continue_as_new_recommended`. Each dimension (event count, payload size,
fan-out) has a soft (warning) threshold and a hard (continue-as-new) threshold.
Reaching any soft threshold flips pressure to `approaching`; reaching any hard
threshold flips it to `continue_as_new_recommended` and sets
`shouldContinueAsNew()` to true. These are advisory authoring signals;
workflow code still chooses when to call `continueAsNew()`.

## Activity idempotency surface

Activity code receives durable execution identity as its default idempotency
surface. `activity_execution_id` identifies the logical activity execution and
is stable across retries. `activity_attempt_id` identifies one physical attempt
and changes on retry. Remote activity adapters expose these fields, and their
task payloads also carry an `idempotency_key` chosen from the durable execution
or attempt context.

Authors should use `activity_execution_id` for external systems that dedupe one
logical request across retries, and `activity_attempt_id` only when an external
system needs one distinct key per attempt.

## Schedule definition boundary

Schedules are first-class durable definitions, not process-local cron callbacks.
Schedule creation persists a `workflow_schedules` row, writes schedule audit
history such as `ScheduleCreated`, maintains the next fire time as the durable
timer for the next occurrence, and fires through the normal start-command path
so `StartAccepted` or `StartRejected` records remain visible.

The schedule API may provide convenience constructors, but the compiled shape is
the durable contract: schedule state, timer/due-time metadata, command context,
overlap policy, trigger audit records, and started workflow run identity.

## SDK and client adapter boundary

SDKs and clients should be thin adapters over durable command results and
visibility contracts. They should translate transport responses into stable
values such as command outcome, workflow instance id, workflow run id, query
result, update status, schedule description, list filters, and run detail views.
They should not infer hidden state by reading workflow internals.

Server, CLI, Waterline, and SDK surfaces should converge on the same command
result and visibility vocabulary so users can move between tools without
learning a different model for the same durable event.

## Type registration and future SDKs

PHP may continue to support `#[Type]` attributes, but durable type identity must
grow toward explicit manifests for Python and future workers. Explicit workflow,
activity, and exception type maps are the cross-language boundary; class names
are PHP implementation details.

Future non-PHP SDKs target the same history event catalog, version-marker
rules, cancellation semantics, update lifecycle, child workflow behavior, and
continue-as-new rules. Cross-SDK additions must introduce new commands or event
types when an existing frozen event shape cannot represent the behavior.

## Migration and reference architectures

Migration helpers and reference architectures are part of the authoring story.
They should help teams move from older generator or stub-heavy code to the
straight-line helper model, validate type maps, run replay checks, inspect
determinism diagnostics, and choose reference deployment patterns without
changing workflow definitions for each runtime mode.

Examples should prefer the smallest durable primitive that exposes the command
or replay boundary. Convenience wrappers are acceptable only when the generated
history, command result, and visibility behavior remain obvious from the call
site.

## Test strategy alignment

This contract is pinned by
`tests/Unit/V2/AuthoringDefinitionBoundaryDocumentationTest.php`, the facade
tests in `tests/Unit/V2/WorkflowFacadeTest.php`, helper autoload coverage in
`tests/Unit/V2/FunctionsAutoloadCompatibilityTest.php`, and the execution,
determinism, schedule, history-event, and type-registry test suites.

## Changing this contract

Changing the primary helper vocabulary, the `Workflow` facade boundary, the
`WorkflowStub` remote-handle role, replay-unsafe diagnostic behavior, schedule
compiled shape, activity idempotency fields, or cross-SDK history rules is a
contract change. Update this document, the API stability document, and the
tests that pin the affected surface in the same change.
