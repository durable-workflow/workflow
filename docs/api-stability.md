# API Stability

This document describes the v2 public API stability contract of the
`durable-workflow/workflow` package.

## Scope

The workflow package is consumed by three distinct audiences:

1. **Workflow authors** — application code that defines workflows and
   activities.
2. **Host integrators** — applications that embed the queue runner, service
   provider, and background sweeps (the "embedded" deployment mode).
3. **External components** — most notably the standalone `workflow-server`
   HTTP service, the `cli`, and managed `cloud` control plane. These call
   into the workflow package as a library but do not author workflows.

The semver guarantee below covers all three surfaces. Breaking changes to
anything marked `@api` require a major version bump.

## What is covered

A class, interface, method, constant, or property is part of the public API
surface if **any** of the following is true:

- The class/interface is in `Workflow\V2\Contracts` and is not marked
  `@internal`.
- The class has an `@api` annotation in its docblock.
- The class is in `Workflow\V2\Enums` (all enum cases are stable).
- The class is in `Workflow\V2\Models` and is documented as a persisted
  Eloquent model (schema changes go through migrations, not code rename).
- The method or constant is marked `@api` individually.

Everything else — including most helpers under `Workflow\V2\Support\*` that
are not explicitly marked — is internal and may change in a minor release.

## Server-facing `Support\*` stability list

The following classes carry an `@api` annotation because the standalone
`workflow-server` uses them directly rather than through a contract. They
are covered by the semver guarantee until their server-facing method surface
is either promoted to a `Contracts\*` interface or removed:

- `Workflow\V2\Support\ActivityTimeoutEnforcer`
- `Workflow\V2\Support\ConfiguredV2Models`
- `Workflow\V2\Support\HistoryPayloadCompression`
- `Workflow\V2\Support\OperatorQueueVisibility`
- `Workflow\V2\Support\PayloadEnvelopeResolver`
- `Workflow\V2\Support\ReplayState`
- `Workflow\V2\Support\ScheduleManager`
- `Workflow\V2\Support\ScheduleStartResult`
- `Workflow\V2\Support\StructuralLimits`
- `Workflow\V2\Support\TaskRepairCandidates`
- `Workflow\V2\Support\TaskRepairPolicy`
- `Workflow\V2\Support\WorkerCompatibilityFleet`
- `Workflow\V2\Support\WorkerProtocolVersion`
- `Workflow\V2\Support\WorkflowCommandNormalizer`
- `Workflow\V2\Support\WorkflowReplayer`
- `Workflow\V2\Support\WorkflowTaskOwnership`
- `Workflow\V2\TaskWatchdog`

For these classes the semver guarantee is:

- The class name, namespace, and `final` / non-`final` disposition are
  stable.
- Public constructor and public static/instance method signatures
  (parameter names, types, return types, thrown exception types) are
  stable.
- Public constants (name, value, type) are stable.
- Public readonly properties on value objects are stable.

Additive changes — new public methods, new optional parameters with
defaults, new constants — are minor-version changes and do not require a
major bump.

## `Workflow\V2\Workflow` authoring facade

The abstract base class `Workflow\V2\Workflow` is the stable authoring API
for v2 workflows. It exposes two surfaces, both covered by the semver
guarantee:

- **Instance members** applications rely on inside a `handle()` method:
  `workflowId()`, `runId()`, `lastChild()`,
  `children()`, `historyLength()`, `historySize()`, `shouldContinueAsNew()`,
  `addCompensation()`, `setParallelCompensation()`,
  `setContinueWithError()`, `compensate()`, and the public properties
  `$run`, `$connection`, `$queue`.
- **Static method facade** mirroring the helpers in
  `Workflow\V2\functions.php`: `activity`, `executeActivity`, `child`,
  `executeChildWorkflow`, `async`, `all`, `parallel`, `await`,
  `awaitWithTimeout`, `awaitSignal`, `timer`, `sideEffect`, `uuid4`,
  `uuid7`, `continueAsNew`, `getVersion`, `patched`, `deprecatePatch`,
  `upsertMemo`, `upsertSearchAttributes`, and the timer sugar
  `seconds`/`minutes`/`hours`/`days`/`weeks`/`months`/`years`.

The namespaced helper functions under `Workflow\V2\*` remain the
equivalent functional-style surface and are equally stable. Choosing
between the static facade and the namespaced helpers is a style
preference; both produce identical `Support\*` Call value objects.

Adding new static methods to the facade is an additive (non-breaking)
change. Removing or renaming a documented method is a major change.

## Pre-existing `Contracts\*` interfaces

Interfaces under `Workflow\V2\Contracts\*` are the preferred extension
point for external components. Implementations of these interfaces are
expected to track the interface as it evolves; adding a method to a
contract is a major-version change.

## Frozen history-event wire formats

History events are the durable, workflow-lifetime protocol. Once a
workflow writes an event to `workflow_history_events`, every future
SDK version that replays that workflow must decode the same field set.
Renaming, removing, or repurposing a field in any published event is a
protocol break — not a minor-version change — regardless of whether
the PHP class that produced the event is `@internal`.

Every stable event schema must name both its frozen payload keys and at
least one replay or projection consumer. Frequently replayed event
families are enumerated first because they sit on the hot path for
cross-SDK replay:

| event | frozen payload keys | primary replay / projection consumers |
| --- | --- | --- |
| `WorkflowStarted` | `workflow_class`, `workflow_type`, `workflow_instance_id`, `workflow_run_id`, `workflow_command_id`, `business_key`, `visibility_labels`, `memo`, `search_attributes`, `execution_timeout_seconds`, `run_timeout_seconds`, `execution_deadline_at`, `run_deadline_at`, `workflow_definition_fingerprint`, `declared_queries`, `declared_query_contracts`, `declared_signals`, `declared_signal_contracts`, `declared_updates`, `declared_update_contracts`, `declared_entry_method`, `declared_entry_mode`, `declared_entry_declaring_class` | `WorkflowDefinitionFingerprint`, `RunLineageView`, worker history payload consumers |
| `ActivityScheduled` | `activity_execution_id`, `activity_class`, `activity_type`, `sequence`, `activity` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer`, `ActivityRecovery` |
| `ActivityCompleted` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `result`, `payload_codec`, `activity`, `parallel_group_path` | `WorkflowExecutor`, `QueryStateReplayer`, `ParallelChildGroup`, `ActivityRecovery` |
| `TimerScheduled` | `timer_id`, `sequence`, `delay_seconds`, `fire_at`, `timer_kind`, `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `signal_wait_id`, `signal_name` | `WorkflowStepHistory`, `QueryStateReplayer`, `RunTimerView`, `ConditionWaits`, `SignalWaits` |
| `TimerFired` | `timer_id`, `sequence`, `delay_seconds`, `fired_at`, `timer_kind`, `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `signal_wait_id`, `signal_name` | `WorkflowStepHistory`, `QueryStateReplayer`, `RunTimerView`, `ConditionWaits`, `SignalWaits` |
| `SignalReceived` | `workflow_command_id`, `signal_id`, `workflow_instance_id`, `workflow_run_id`, `signal_name`, `signal_wait_id` | `SignalWaits`, `RunSignalView`, worker history payload consumers |
| `SignalApplied` | `workflow_command_id`, `signal_id`, `signal_name`, `signal_wait_id`, `sequence`, `value` | `WorkflowStepHistory`, `SignalWaits`, `RunSignalView`, `QueryStateReplayer` |
| `UpdateAccepted` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `arguments` | `RunUpdateView`, worker history payload consumers |
| `UpdateApplied` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `arguments`, `sequence` | `QueryStateReplayer`, `RunUpdateView`, worker history payload consumers |
| `UpdateCompleted` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `sequence`, `result` | `RunUpdateView`, worker history payload consumers |
| `ConditionWaitOpened` | `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `sequence`, `timeout_seconds` | `WorkflowStepHistory`, `ConditionWaits`, worker history payload consumers |
| `SideEffectRecorded` | `sequence`, `result` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer` |
| `VersionMarkerRecorded` | `sequence`, `change_id`, `version`, `min_supported`, `max_supported` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer` |
| `ChildWorkflowScheduled` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `parent_close_policy`, `retry_policy`, `timeout_policy` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer`, `ChildRunHistory`, `RunLineageView` |
| `ChildRunStarted` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `retry_policy`, `timeout_policy`, `execution_timeout_seconds`, `run_timeout_seconds`, `execution_deadline_at`, `run_deadline_at` | `ChildRunHistory`, `RunLineageView`, worker history payload consumers |
| `ChildRunCompleted` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `closed_reason`, `closed_at`, `output`, `parallel_group_path` | `WorkflowExecutor`, `QueryStateReplayer`, `ChildRunHistory`, `ParallelChildGroup`, `RunLineageView` |

The key list is a wire-format list, not a promise that every event row
contains every key. Some keys are optional because older rows predate a
projection, a branch does not have that attribute, or `array_filter`
omitted a null value. Consumers must continue to accept missing optional
keys indefinitely. Producers must not rename, remove, or change the type
or meaning of an existing key.

### `VersionMarkerRecorded`

This marker records the result of `Workflow::getVersion()`, `Workflow::patched()`,
or `Workflow::deprecatePatch()` (PHP) and `workflow.get_version()`,
`workflow.patched()`, or `workflow.deprecate_patch()` (Python SDK). The moment an operational
workflow writes a `VersionMarkerRecorded` event, every replayer for
the rest of that workflow's lifetime must continue to decode the same
payload. See PHP `Workflow\V2\Support\DefaultWorkflowTaskBridge::applyRecordVersionMarker()`
and Python `durable_workflow.workflow._workflow_state` for the
authoritative emission and replay sites.

`patched(change_id)` and `deprecatePatch(change_id)` / `deprecate_patch(change_id)`
are additive sugar over this same frozen shape. They do not introduce a new
event type: patched markers use `min_supported = -1`, `max_supported = 1`,
and `version = 1`; replaying version `-1` means the workflow reached the patch
site before the patch marker existed.

**Payload shape — frozen:**

| key             | type    | meaning                                   |
| --------------- | ------- | ----------------------------------------- |
| `sequence`      | integer | 1-indexed command sequence inside the task |
| `change_id`     | string  | workflow author's identifier for the versioning point |
| `version`       | integer | version recorded for this `change_id`     |
| `min_supported` | integer | minimum version the author commits to replay |
| `max_supported` | integer | maximum version supported at record time  |

**Matching command wire format (`record_version_marker`) — frozen:**

| key             | type    | meaning                                   |
| --------------- | ------- | ----------------------------------------- |
| `type`          | string  | constant `"record_version_marker"`        |
| `change_id`     | string  | — same semantics as above —               |
| `version`       | integer |                                           |
| `min_supported` | integer |                                           |
| `max_supported` | integer |                                           |

**Evolution rules:**

- Adding a field to either shape is a protocol break. Replayers running
  older SDK builds will silently ignore the field, producing decisions
  that diverge from replayers on the new build. Treat any new shape as
  a **second, parallel primitive** with a new command/event type name —
  never as an in-place extension of the existing one.
- Renaming or removing a field is also a protocol break. Old workflow
  rows still carry the old key. Keep the old key supported indefinitely.
- Changing a field's type (e.g. integer → string) or its semantic
  meaning is a protocol break even if the JSON shape decodes.
- The set of SDKs that read this shape is not limited to the SDKs in
  `repos/*`. Any third-party SDK may be replaying historic runs; the
  wire format is a public protocol, not a private contract between the
  packages in this fleet.

Parity between PHP and Python emission/replay sites is pinned by
`tests/Unit/V2/VersionMarkerWireFormatTest.php` (PHP, in this repo)
and by the canonical fixture it snapshots. Any change to the PHP emit
site that shifts keys, types, or emission order must update the test
and the Python replay site (`repos/sdk-python/src/durable_workflow/workflow.py`)
in the same change.

**Broader history-event taxonomy.** The same freeze-at-stable rule
applies to every `HistoryEventType` case. The full per-event schema
enumeration is tracked as follow-up work before 2.0.0 stable; until
then, treat `Workflow\V2\Support\DefaultWorkflowTaskBridge` as the
authoritative emission site and `Workflow\V2\Models\WorkflowHistoryEvent`
rows as the authoritative persisted shape.

## Signal And Update Payload Decode Failures

Signal and update payload decode failures are operational failures, not
silent replay skips. When v2 decodes a persisted signal/update history
payload or a queued signal/update command payload, the worker logs a
`Workflow payload decode failed.` warning with workflow-scoped context:
`workflow_id`, `run_id`, `event_id` or `workflow_command_id`,
`signal_name` or `update_name`, `codec`, `exception_type`, and a short
`payload_head` prefix for triage.

The default policy is fail-visible: replay surfaces
`Workflow\V2\Exceptions\WorkflowPayloadDecodeException`, and worker
execution records the failure through the normal workflow/update failure
path rather than dropping the signal or update. Hosts that need a
drop-or-DLQ policy should implement that outside the replay decoder so the
durable history still records that the payload was malformed.

## Changing this list

Any pull request that removes a class from the list, changes a signature
on a class in the list, or narrows a return type must either be shipped
in a major version, or promote the class to a `Contracts\*` interface in
the same change. Reviewers should treat unmotivated removals from this
list as a breaking change.
