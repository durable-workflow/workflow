<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * @internal Exact-address fencing for the unfrozen scope model.
 * A fence denies publication. Only the original owner's stop receipt proves exit.
 * This retains the hosting workflow claim so unrelated local siblings can continue.
 */
final class ScopedActivityCancellation
{
    /**
     * @return array<string, mixed>
     */
    public static function fence(
        WorkflowRun $run,
        WorkflowTask $workflowTask,
        string $executionId,
        string $scopeId,
        string $requestId,
        string $protocolVersion,
    ): array {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        return $run->getConnection()
            ->transaction(static function () use ($run, $workflowTask, $executionId, $scopeId, $requestId): array {
                // Preserve the worker's activity attempt/execution lock prefix.
                $rows = ActivityRowLockOrder::lockForExecution($executionId, true);
                $execution = $rows['execution'];
                if (! $execution instanceof ActivityExecution || $execution->workflow_run_id !== $run->id) {
                    throw new LogicException('cancellation_scope_activity_not_found');
                }
                if ($execution->current_attempt_id !== $rows['snapshot_attempt_id']
                    || ($execution->current_attempt_id !== null && $rows['attempt'] === null)
                    || ($rows['attempt'] !== null && ($rows['attempt']->workflow_run_id !== $run->id
                        || $rows['attempt']->activity_execution_id !== $executionId))
                    || ($execution->status === ActivityStatus::Running && $rows['attempt'] === null)) {
                    throw new LogicException('cancellation_scope_activity_attempt_changed');
                }
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                /** @var WorkflowTask|null $claim */
                $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find(
                    $workflowTask->id
                );
                if ($claim === null || $claim->workflow_run_id !== $locked->id || $claim->namespace !== $locked->namespace
                    || $claim->task_type !== TaskType::Workflow || $claim->status !== TaskStatus::Leased
                    || $claim->lease_owner === null || $claim->lease_owner === '' || $claim->attempt_count < 1
                    || $claim->lease_owner !== $workflowTask->lease_owner || $claim->attempt_count !== $workflowTask->attempt_count
                    || $claim->lease_expires_at === null || now()
                        ->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                $context = CancellationScopeRequests::context($locked, $scopeId);
                if ($context === null || $context->requestId !== $requestId) {
                    throw new LogicException('cancellation_scope_request_mismatch');
                }
                $authority = CancellationScopeRequests::authority($locked, $scopeId);
                if (! $authority['active'] || $authority['deadline_at'] === null) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $request = $locked->historyEvents()
                    ->where('event_type', HistoryEventType::CancellationScopeRequested)
                    ->where('payload->scope_id', $scopeId)
                    ->sole();
                $scope = CancellationScopeHistory::forRun($locked)[$scopeId];
                if ($scope['shield_parent'] && $request->payload['parent_scope_id'] !== null) {
                    throw new LogicException('cancellation_scope_parent_shielded');
                }
                $metadata = [
                    'schema' => ActivityCancellationContext::SCOPE_SCHEMA,
                    'workflow_run_id' => $locked->id,
                    'scope_id' => $scopeId,
                    'request_id' => $requestId,
                    'request_history_event_id' => $request->id,
                    'cancellation' => $context->toArray(),
                    'authority_deadline_at' => $authority['deadline_at'],
                ];
                if (ActivityCancellationContext::forScopeSnapshot(
                    $locked,
                    $metadata,
                    $executionId,
                    $execution->sequence
                ) === null
                    || ($execution->activity_options['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID) !== $scopeId) {
                    throw new LogicException('cancellation_scope_activity_membership_mismatch');
                }
                $policy = ActivityCancellationWait::policy($locked, $execution->sequence);
                $local = LocalActivityRuntime::isExecution($execution);
                if ($policy === CancellationPolicy::Abandon) {
                    if ($local || $execution->schedule_to_close_deadline_at === null) {
                        throw new LogicException('cancellation_scope_activity_abandon_not_supported');
                    }
                    return [
                        'fenced' => false,
                        'abandoned' => true,
                        'waiting_for_stop' => false,
                        'history_event_id' => null,
                    ];
                }
                $existing = $locked->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancelled)
                    ->where('payload->activity_execution_id', $executionId)
                    ->first();
                if ($existing !== null) {
                    $original = ActivityCancellationContext::forEvent($locked, $existing);
                    if ($original === null || $original->requestId !== $requestId) {
                        throw new LogicException('activity_cancellation_owned_by_another_request');
                    }
                    $event = $existing;
                } elseif (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                    $activityTask = $local ? $claim : null;
                    if (! $local) {
                        $tasks = $locked->tasks()
                            ->where('task_type', TaskType::Activity)
                            ->where('payload->activity_execution_id', $executionId);
                        $activityTask = $execution->status === ActivityStatus::Running
                            ? $tasks->whereKey($rows['attempt']->workflow_task_id)->lockForUpdate()->sole()
                            : $tasks->whereIn('status', [TaskStatus::Ready, TaskStatus::Leased])->lockForUpdate()
                                ->sole();
                    }
                    $event = ActivityCancellation::record($locked, $execution, $activityTask, $requestId, $metadata);
                    if (! $event instanceof WorkflowHistoryEvent) {
                        throw new LogicException('cancellation_scope_activity_fence_not_recorded');
                    }
                } else {
                    // A naturally completed operation is not converted into cancellation.
                    $locked->unsetRelation('historyEvents');
                    if (! in_array($execution->status, [ActivityStatus::Completed, ActivityStatus::Failed], true)
                        || ! ActivityCancellationCompletion::resolved($locked, $executionId, $context)) {
                        throw new LogicException('cancellation_scope_activity_terminal_history_not_recorded');
                    }
                    return [
                        'fenced' => false,
                        'abandoned' => false,
                        'waiting_for_stop' => false,
                        'history_event_id' => null,
                    ];
                }
                $locked->unsetRelation('historyEvents');
                return [
                    'fenced' => true,
                    'abandoned' => false,
                    'history_event_id' => $event->id,
                    'request_id' => $requestId,
                    'root_request_id' => $context->rootContext->rootRequestId,
                    'scope_id' => $scopeId,
                    'cleanup_deadline_at' => $context->deadline()
                        ->toISOString(),
                    'cancellation_scope' => $event->payload['cancellation_scope'],
                    'waiting_for_stop' => $policy === CancellationPolicy::WaitCancellationCompleted
                        && ! ActivityCancellationCompletion::resolved($locked, $executionId, $context),
                ];
            }, 5);
    }
}
