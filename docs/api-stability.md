# API Stability

This document describes the v2 public API stability contract of the
`durable-workflow/workflow` package.

## Relationship to the platform compatibility authority

This document is the per-package stability contract for the PHP workflow
package. It is **downstream** of the platform-wide canonical compatibility
and release-authority page:

- <https://durable-workflow.github.io/docs/2.0/compatibility>
- machine-readable mirror: `surface_stability_contract` in
  `GET /api/cluster/info`, schema
  `durable-workflow.v2.surface-stability.contract`, version `1`.

The platform authority defines stability levels (`frozen`, `stable`,
`prerelease`, `experimental`), the patch/minor/major change rules, the
diagnostic-vs-guaranteed-field rule, and the surface family table that
covers the server API, worker protocol, CLI JSON, Waterline API, MCP
discovery/results, official SDKs, and history-event wire formats. This
document adds workflow-package-specific detail under those rules; it must
not contradict the platform authority. When this document and the
platform authority disagree, the platform authority wins, and the
disagreement is a bug here.

The platform surface families that own the contents of this document are
`official_sdks` (for the `Workflow` PHP authoring and `Support\*` API)
and `history_event_wire_formats` (for the frozen-event tables below).

## Relationship to the platform conformance suite

This document declares *what* the per-package contract is. The platform
conformance suite — specified in
[`docs/architecture/platform-conformance-suite.md`](architecture/platform-conformance-suite.md)
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite` — defines
*how* an implementation proves it follows the contract: the target
matrix, the fixture catalog, the pass / fail rules, and the release
gates. The PHP workflow package claims the `official_sdk` and
`worker_protocol_implementation` targets; releases of this package must
attach a passing harness result document before tag.

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
- `Workflow\V2\Support\BundleIntegrityVerifier`
- `Workflow\V2\Support\ConfiguredV2Models`
- `Workflow\V2\Support\HistoryPayloadCompression`
- `Workflow\V2\Support\OperatorQueueVisibility`
- `Workflow\V2\Support\PayloadEnvelopeResolver`
- `Workflow\V2\Support\ReplayDiff`
- `Workflow\V2\Support\ReplayState`
- `Workflow\V2\Support\ScheduleManager`
- `Workflow\V2\Support\ScheduleStartResult`
- `Workflow\V2\Support\StructuralLimits`
- `Workflow\V2\Support\SurfaceStabilityContract`
- `Workflow\V2\Support\TaskRepairCandidates`
- `Workflow\V2\Support\TaskRepairPolicy`
- `Workflow\V2\Support\WorkerCompatibilityFleet`
- `Workflow\V2\Support\WorkerProtocolVersion`
- `Workflow\V2\Support\WorkflowCommandNormalizer`
- `Workflow\V2\Support\WorkflowReplayer`
- `Workflow\V2\Support\WorkflowRunRetentionCleanup`
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

## Durable Message Stream Contract

The v2 durable message service is the stable lower-level contract backing
signals, updates, workflow-to-workflow messages, and repeated human-input
flows:

- `MessageService::sendMessage()` creates paired outbound and inbound
  `workflow_messages` rows with one reserved instance sequence.
- `MessageService::peekMessages()` and `receiveMessages()` read pending
  inbound messages after the run cursor. They are intentionally read-only:
  they do not mark messages consumed and do not advance the cursor.
- `MessageService::consumeMessage()` and `consumeMessages()` are the only
  message-service APIs that mark messages consumed and advance the cursor.
  Batch consumption is same-stream only; mixed-stream batches are rejected so
  each `MessageCursorAdvanced` event names exactly one `stream_key`.
- `MessageService::transferMessagesToContinuedRun()` moves pending inbound
  messages and the cursor position from the closing run to the continued run.
  Consumed messages stay attached to the original run as historical record.

## Continue-As-New Interleaving Contract

Continue-as-new keeps one logical workflow instance while closing one run
and creating the next run. Commands that target the logical instance keep
their ordering and lifecycle across that boundary:

- Signals are ordered by the instance message stream. A signal accepted
  before the continue-as-new transition commits remains pending until the
  continued run consumes it. Cursor transfer is durable and monotonic.
- Instance-scoped updates accepted before the transition but not yet
  applied are carried to the continued run. The update id remains stable,
  `inspectUpdate()` follows the same lifecycle row, and the continued run
  records the `UpdateApplied` / `UpdateCompleted` history for the update.
- Run-targeted commands are bound to their selected run. They are not
  retargeted to a continued run; callers that need logical-workflow
  behavior should use the instance-scoped command surface.
- Queries are non-durable reads. A query resolves the current run at the
  time the query executes; if the continue-as-new transaction has already
  committed, the query reads the continued run, otherwise it reads the
  still-current closing run. Queries are not buffered or replayed.

This contract is intentionally instance-first so external server, CLI,
and SDK callers can reason about a stable logical workflow id without
having to retry around the brief run handoff window.

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
| `StartAccepted` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `workflow_class`, `workflow_type`, `business_key`, `visibility_labels`, `memo`, `search_attributes`, `outcome`, `rejection_reason` | `HistoryTimeline`, `HistoryExport`, `RunCommandContract`, operator command projections |
| `StartRejected` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `workflow_class`, `workflow_type`, `business_key`, `visibility_labels`, `memo`, `search_attributes`, `outcome`, `rejection_reason` | `HistoryTimeline`, `HistoryExport`, `RunCommandContract`, operator command projections |
| `WorkflowStarted` | `workflow_class`, `workflow_type`, `workflow_instance_id`, `workflow_run_id`, `workflow_command_id`, `business_key`, `visibility_labels`, `memo`, `search_attributes`, `execution_timeout_seconds`, `run_timeout_seconds`, `execution_deadline_at`, `run_deadline_at`, `workflow_definition_fingerprint`, `declared_queries`, `declared_query_contracts`, `declared_signals`, `declared_signal_contracts`, `declared_updates`, `declared_update_contracts`, `declared_entry_method`, `declared_entry_mode`, `declared_entry_declaring_class`, `parent_workflow_instance_id`, `parent_workflow_run_id`, `parent_sequence`, `workflow_link_id`, `child_call_id`, `retry_policy`, `timeout_policy`, `continued_from_run_id`, `retry_attempt`, `retry_of_child_workflow_run_id` | `WorkflowDefinitionFingerprint`, `RunLineageView`, worker history payload consumers |
| `WorkflowContinuedAsNew` | `sequence`, `continued_to_run_id`, `continued_to_run_number`, `workflow_link_id`, `closed_reason` | `WorkflowStepHistory`, `RunLineageView`, `HistoryTimeline`, operator detail projections |
| `ActivityScheduled` | `activity_execution_id`, `activity_class`, `activity_type`, `sequence`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer`, `ActivityRecovery`, `ParallelChildGroup` |
| `ActivityStarted` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ActivitySnapshot`, `ActivityAttemptSnapshots`, `HistoryTimeline`, `RunActivityView`, `ParallelChildGroup` |
| `ActivityHeartbeatRecorded` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `heartbeat_at`, `lease_expires_at`, `activity`, `activity_attempt`, `progress` | `ActivitySnapshot`, `ActivityAttemptSnapshots`, `HistoryTimeline`, `RunActivityView` |
| `ActivityRetryScheduled` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `retry_task_id`, `retry_of_task_id`, `retry_available_at`, `retry_backoff_seconds`, `retry_after_attempt_id`, `retry_after_attempt`, `max_attempts`, `retry_policy`, `timeout_kind`, `exception_type`, `exception_class`, `message`, `code`, `exception`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ActivitySnapshot`, `ActivityAttemptSnapshots`, `HistoryTimeline`, `RunTaskView`, `ParallelChildGroup` |
| `ActivityCompleted` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `result`, `payload_codec`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `WorkflowExecutor`, `QueryStateReplayer`, `ParallelChildGroup`, `ActivityRecovery` |
| `ActivityFailed` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `failure_id`, `failure_category`, `non_retryable`, `exception_type`, `exception_class`, `message`, `code`, `exception`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path`, `structural_limit_kind`, `structural_limit_value`, `structural_limit_configured` | `ActivitySnapshot`, `FailureSnapshots`, `HistoryTimeline`, `ParallelFailureSelector`, `ParallelChildGroup` |
| `ActivityCancelled` | `workflow_command_id`, `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `cancelled_at`, `activity`, `activity_attempt` | `ActivitySnapshot`, `ActivityAttemptSnapshots`, `HistoryTimeline`, cancellation repair projections |
| `ActivityTimedOut` | `activity_execution_id`, `activity_attempt_id`, `activity_class`, `activity_type`, `sequence`, `attempt_number`, `failure_id`, `failure_category`, `timeout_kind`, `message`, `exception_class`, `schedule_deadline_at`, `close_deadline_at`, `schedule_to_close_deadline_at`, `heartbeat_deadline_at`, `activity`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ActivitySnapshot`, `FailureSnapshots`, `HistoryTimeline`, `ParallelChildGroup`, timeout repair projections |
| `TimerScheduled` | `timer_id`, `sequence`, `delay_seconds`, `fire_at`, `timer_kind`, `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `signal_wait_id`, `signal_name` | `WorkflowStepHistory`, `QueryStateReplayer`, `RunTimerView`, `ConditionWaits`, `SignalWaits` |
| `TimerFired` | `timer_id`, `sequence`, `delay_seconds`, `fire_at`, `fired_at`, `timer_kind`, `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `signal_wait_id`, `signal_name` | `WorkflowStepHistory`, `QueryStateReplayer`, `RunTimerView`, `ConditionWaits`, `SignalWaits` |
| `TimerCancelled` | `timer_id`, `sequence`, `delay_seconds`, `fire_at`, `timer_kind`, `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `signal_wait_id`, `signal_name`, `cancelled_at` | `WorkflowStepHistory`, `RunTimerView`, `ConditionWaits`, `SignalWaits`, `HistoryTimeline` |
| `SignalReceived` | `workflow_command_id`, `signal_id`, `workflow_instance_id`, `workflow_run_id`, `signal_name`, `signal_wait_id` | `SignalWaits`, `RunSignalView`, worker history payload consumers |
| `SignalApplied` | `workflow_command_id`, `signal_id`, `signal_name`, `signal_wait_id`, `sequence`, `value` | `WorkflowStepHistory`, `SignalWaits`, `RunSignalView`, `QueryStateReplayer` |
| `SignalWaitOpened` | `signal_name`, `signal_wait_id`, `sequence`, `timeout_seconds` | `WorkflowStepHistory`, `SignalWaits`, `RunSignalView`, `HistoryTimeline` |
| `UpdateAccepted` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `arguments` | `RunUpdateView`, worker history payload consumers |
| `UpdateRejected` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `arguments`, `validation_errors` | `RunUpdateView`, `HistoryTimeline`, command-contract projections |
| `UpdateApplied` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `arguments`, `sequence` | `QueryStateReplayer`, `RunUpdateView`, worker history payload consumers |
| `UpdateCompleted` | `workflow_command_id`, `update_id`, `workflow_instance_id`, `workflow_run_id`, `update_name`, `sequence`, `result`, `failure_id`, `failure_category`, `non_retryable`, `exception_type`, `exception_class`, `message`, `code`, `exception` | `RunUpdateView`, worker history payload consumers |
| `ConditionWaitOpened` | `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `sequence`, `timeout_seconds` | `WorkflowStepHistory`, `ConditionWaits`, worker history payload consumers |
| `ConditionWaitSatisfied` | `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `sequence`, `timer_id`, `timeout_seconds`, `workflow_signal_id`, `signal_name`, `signal_wait_id` | `WorkflowStepHistory`, `ConditionWaits`, `QueryStateReplayer`, `HistoryTimeline` |
| `ConditionWaitTimedOut` | `condition_wait_id`, `condition_key`, `condition_definition_fingerprint`, `sequence`, `timer_id`, `timeout_seconds` | `WorkflowStepHistory`, `ConditionWaits`, `QueryStateReplayer`, `HistoryTimeline` |
| `SideEffectRecorded` | `sequence`, `result` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer` |
| `VersionMarkerRecorded` | `sequence`, `change_id`, `version`, `min_supported`, `max_supported` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer` |
| `ChildWorkflowScheduled` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `parent_close_policy`, `retry_policy`, `timeout_policy`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `WorkflowStepHistory`, `WorkflowExecutor`, `QueryStateReplayer`, `ChildRunHistory`, `ParallelChildGroup`, `RunLineageView` |
| `ChildRunStarted` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `parent_close_policy`, `retry_policy`, `timeout_policy`, `execution_timeout_seconds`, `run_timeout_seconds`, `execution_deadline_at`, `run_deadline_at`, `retry_attempt`, `retry_of_child_workflow_run_id`, `retry_backoff_seconds`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ChildRunHistory`, `ParallelChildGroup`, `RunLineageView`, worker history payload consumers |
| `ChildRunCompleted` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `closed_reason`, `closed_at`, `output`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `WorkflowExecutor`, `QueryStateReplayer`, `ChildRunHistory`, `ParallelChildGroup`, `RunLineageView` |
| `ChildRunFailed` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `closed_reason`, `closed_at`, `failure_id`, `failure_category`, `exception_type`, `exception_class`, `message`, `exception`, `code`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ChildRunHistory`, `FailureSnapshots`, `ParallelChildGroup`, `ParallelFailureSelector`, `RunLineageView` |
| `ChildRunCancelled` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `closed_reason`, `closed_at`, `failure_id`, `failure_category`, `exception_class`, `message`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ChildRunHistory`, `FailureSnapshots`, `ParallelChildGroup`, `RunLineageView`, parent-close projections |
| `ChildRunTerminated` | `sequence`, `workflow_link_id`, `child_call_id`, `child_workflow_instance_id`, `child_workflow_run_id`, `child_workflow_class`, `child_workflow_type`, `child_run_number`, `child_status`, `closed_reason`, `closed_at`, `failure_id`, `failure_category`, `exception_class`, `message`, `parallel_group_id`, `parallel_group_kind`, `parallel_group_base_sequence`, `parallel_group_size`, `parallel_group_index`, `parallel_group_path` | `ChildRunHistory`, `FailureSnapshots`, `ParallelChildGroup`, `RunLineageView`, parent-close projections |
| `SearchAttributesUpserted` | `sequence`, `attributes`, `merged` | `WorkflowStepHistory`, `HistoryTimeline`, visibility/search projections, history export |
| `MemoUpserted` | `sequence`, `entries`, `merged` | `WorkflowStepHistory`, `HistoryTimeline`, run detail projections, history export |
| `RepairRequested` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `command_type`, `outcome`, `liveness_state`, `wait_kind`, `task_id`, `task_type` | `HistoryTimeline`, `RunCommandContract`, repair diagnostics, operator detail projections |
| `CancelRequested` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `command_type`, `reason` | `HistoryTimeline`, `RunCommandContract`, cancellation projections |
| `WorkflowCancelled` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `failure_id`, `failure_category`, `closed_reason`, `exception_class`, `message`, `reason` | `HistoryTimeline`, `FailureSnapshots`, `ChildRunHistory`, cancellation projections |
| `TerminateRequested` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `command_type`, `reason` | `HistoryTimeline`, `RunCommandContract`, termination projections |
| `WorkflowTerminated` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `failure_id`, `failure_category`, `closed_reason`, `exception_class`, `message`, `reason` | `HistoryTimeline`, `FailureSnapshots`, `ChildRunHistory`, termination projections |
| `ArchiveRequested` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `command_type`, `outcome`, `reason` | `HistoryTimeline`, `RunCommandContract`, archive/export projections |
| `WorkflowArchived` | `workflow_command_id`, `workflow_instance_id`, `workflow_run_id`, `archive_command_id`, `reason` | `HistoryTimeline`, archive/export projections, operator detail projections |
| `WorkflowTimedOut` | `failure_id`, `timeout_kind`, `failure_category`, `message`, `exception_class`, `execution_deadline_at`, `run_deadline_at` | `FailureSnapshots`, `HistoryTimeline`, `ChildRunHistory`, timeout repair projections |
| `WorkflowCompleted` | `output` | `ChildRunHistory`, `HistoryTimeline`, history export, parent resume projections |
| `WorkflowFailed` | `failure_id`, `source_kind`, `source_id`, `failure_category`, `non_retryable`, `exception_type`, `exception_class`, `message`, `exception`, `structural_limit_kind`, `structural_limit_value`, `structural_limit_configured` | `FailureSnapshots`, `HistoryTimeline`, `ChildRunHistory`, failure repair projections |
| `FailureHandled` | `failure_id`, `sequence`, `failure_category`, `source_kind`, `source_id`, `propagation_kind`, `exception_class`, `exception_type`, `message`, `handled` | `FailureSnapshots`, `HistoryTimeline`, `QueryStateReplayer`, operator detail projections |
| `ParentClosePolicyApplied` | `child_instance_id`, `child_run_id`, `policy`, `reason` | `HistoryTimeline`, parent-close diagnostics, history export |
| `ParentClosePolicyFailed` | `child_instance_id`, `child_run_id`, `policy`, `reason`, `error` | `HistoryTimeline`, parent-close diagnostics, history export |
| `MessageCursorAdvanced` | `stream_key`, `previous_position`, `new_position` | `MessageStreamCursor`, `HistoryTimeline`, signal/update interleave diagnostics |
| `ScheduleCreated` | `spec`, `action`, `overlap_policy`, `next_fire_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |
| `SchedulePaused` | `reason`, `paused_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |
| `ScheduleResumed` | `next_fire_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |
| `ScheduleUpdated` | `changed_fields`, `spec`, `action`, `overlap_policy`, `next_fire_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |
| `ScheduleTriggered` | `workflow_instance_id`, `workflow_run_id`, `schedule_id`, `schedule_ulid`, `cron_expression`, `timezone`, `overlap_policy`, `outcome`, `effective_overlap_policy`, `trigger_number`, `occurrence_time`, `command_context` | `WorkflowScheduleHistoryEvent`, workflow run history, schedule audit views |
| `ScheduleDeleted` | `reason`, `deleted_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |
| `ScheduleTriggerSkipped` | `reason`, `skipped_trigger_count`, `last_skipped_at`, `command_context` | `WorkflowScheduleHistoryEvent`, schedule audit views, history export |

The key list is a wire-format list, not a promise that every event row
contains every key. Some keys are optional because older rows predate a
projection, a branch does not have that attribute, or `array_filter`
omitted a null value. Consumers must continue to accept missing optional
keys indefinitely. Producers must not rename, remove, or change the type
or meaning of an existing key.

## Durable Message Stream Authoring API

The first-class v2 inbox/outbox surface is `Workflow\V2\MessageStream`, opened
from `Workflow::messages()`, `Workflow::inbox()`, `Workflow::outbox()`, or
`MessageService::stream()`.

Stable methods:

- `key(): string`
- `cursor(): int`
- `hasPending(): bool`
- `pendingCount(): int`
- `peek(int $limit = 100): Collection`
- `receive(int $limit = 1, ?int $consumedBySequence = null): Collection`
- `receiveOne(?int $consumedBySequence = null): ?WorkflowMessage`
- `sendReference(string $targetInstanceId, ?string $payloadReference = null, MessageChannel|string $channel = MessageChannel::WorkflowMessage, ?string $correlationId = null, ?string $idempotencyKey = null, array $metadata = [], ?DateTimeInterface $expiresAt = null): WorkflowMessage`

`peek()` is non-mutating. `receive()` and `receiveOne()` consume pending inbound
messages and advance the durable cursor; they must be associated with a
positive workflow history sequence, either from the workflow base class or an
explicit runtime/control-plane caller. `sendReference()` stores a payload
reference and routing metadata only; inline payload storage is not part of the
stable contract.

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
applies to every `HistoryEventType` case, including schedule audit
events stored in `workflow_schedule_history_events`. Representative PHP
emit-site guards currently cover the replay-critical subset above; until
every producer is source-guarded, treat each documented table row as the
minimum stable wire-format contract and treat
`Workflow\V2\Models\WorkflowHistoryEvent` /
`Workflow\V2\Models\WorkflowScheduleHistoryEvent` rows as the
authoritative persisted shape.

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
