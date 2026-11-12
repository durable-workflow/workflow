<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Starts the next attempt of a failed child under the retry policy its parent
 * recorded. The embedded executor and the service-mode bridge share it.
 *
 * A handed-over child (its parent continued as new and a later run of the
 * chain supervises it) is retried the same way, but the closed parent's
 * history is final: nothing is appended to it, and the child's supervision
 * rows move to the retry run instead.
 */
final class ChildRetryStart
{
    /**
     * @return array{WorkflowTask, WorkflowRun}|null The dispatched retry task and run, or null when the failure is final.
     */
    public static function start(
        WorkflowRun $parentRun,
        int $sequence,
        WorkflowRun $failedChildRun,
        bool $handedOver = false,
    ): ?array {
        if (ChildRunHistory::resolvedStatus(null, $failedChildRun) !== RunStatus::Failed) {
            return null;
        }

        $parentRun->loadMissing(
            ['historyEvents', 'childLinks.childRun.instance.currentRun', 'childLinks.childRun.failures']
        );

        $parentLink = ChildRunHistory::latestLinkForSequence($parentRun, $sequence);

        if ($parentLink?->child_workflow_run_id !== $failedChildRun->id) {
            return null;
        }

        $scheduledEvent = ChildRunHistory::scheduledEventForSequence($parentRun, $sequence);
        $retryPolicy = is_array($scheduledEvent?->payload['retry_policy'] ?? null)
            ? $scheduledEvent->payload['retry_policy']
            : null;

        if ($retryPolicy === null) {
            /** @var WorkflowChildCall|null $childCall */
            $childCall = WorkflowChildCall::query()
                ->where('parent_workflow_run_id', $parentRun->id)
                ->where('sequence', $sequence)
                ->first();

            $retryPolicy = is_array($childCall?->retry_policy) ? $childCall->retry_policy : null;
        }

        if ($retryPolicy === null) {
            return null;
        }

        $attemptCount = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->where('link_type', 'child_workflow')
            ->count();

        if ($attemptCount >= ChildWorkflowRetryPolicy::maxAttempts($retryPolicy)) {
            return null;
        }

        if (ChildWorkflowRetryPolicy::isNonRetryableFailure($retryPolicy, $failedChildRun)) {
            return null;
        }

        $timeoutPolicy = is_array($scheduledEvent?->payload['timeout_policy'] ?? null)
            ? $scheduledEvent->payload['timeout_policy']
            : null;
        $executionTimeoutSeconds = is_int($timeoutPolicy['execution_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['execution_timeout_seconds']
            : null;
        $runTimeoutSeconds = is_int($timeoutPolicy['run_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['run_timeout_seconds']
            : null;

        $now = now();
        $backoffSeconds = ChildWorkflowRetryPolicy::backoffSeconds($retryPolicy, $attemptCount);
        $availableAt = $now->copy()
            ->addSeconds($backoffSeconds);

        /** @var WorkflowInstance|null $childInstance */
        $childInstance = WorkflowInstance::query()
            ->lockForUpdate()
            ->find($failedChildRun->workflow_instance_id);

        if (! $childInstance instanceof WorkflowInstance) {
            return null;
        }

        $nextRunNumber = ((int) WorkflowRun::query()
            ->where('workflow_instance_id', $failedChildRun->workflow_instance_id)
            ->max('run_number')) + 1;

        $executionDeadlineAt = $failedChildRun->execution_deadline_at;
        if ($executionDeadlineAt === null && $executionTimeoutSeconds !== null) {
            $executionDeadlineAt = $now->copy()
                ->addSeconds($executionTimeoutSeconds);
        }

        $runDeadlineAt = $runTimeoutSeconds !== null
            ? $now->copy()
                ->addSeconds($runTimeoutSeconds)
            : null;

        /** @var WorkflowRun $retryRun */
        $retryRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $failedChildRun->workflow_instance_id,
            'run_number' => $nextRunNumber,
            'observability_context' => TraceCarrier::fromRun($failedChildRun),
            'workflow_class' => $failedChildRun->workflow_class,
            'workflow_type' => $failedChildRun->workflow_type,
            'namespace' => $failedChildRun->namespace,
            'business_key' => $failedChildRun->business_key,
            'visibility_labels' => $failedChildRun->visibility_labels,
            'status' => RunStatus::Pending->value,
            'compatibility' => $failedChildRun->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $failedChildRun->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $failedChildRun->arguments,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'connection' => $failedChildRun->connection,
            'queue' => $failedChildRun->queue,
            ...TaskSchedulingFields::nonDefault(TaskSchedulingFields::forRun($failedChildRun)),
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        app(MemoUpsertService::class)->inheritFromParent($failedChildRun, $retryRun, 1);
        app(SearchAttributeUpsertService::class)->inheritFromParent($failedChildRun, $retryRun, 1);
        $retryRun->unsetRelation('memos');
        $retryRun->unsetRelation('searchAttributes');

        $childInstance->forceFill([
            'current_run_id' => $retryRun->id,
            'run_count' => max((int) $childInstance->run_count, $nextRunNumber),
        ])->save();

        $childCallId = ChildRunHistory::childCallIdForSequence($parentRun, $sequence) ?? (string) Str::ulid();
        $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence($parentRun, $sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);
        $parentClosePolicy = $parentLink?->parent_close_policy ?? ParentClosePolicy::Abandon->value;

        /** @var WorkflowLink $retryLink */
        $retryLink = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $retryRun->workflow_instance_id,
            'child_workflow_run_id' => $retryRun->id,
            'is_primary_parent' => true,
            'parallel_group_path' => self::parallelGroupPath($parallelMetadata),
            'parent_close_policy' => $parentClosePolicy,
        ]);

        ChildRunHistory::recordRetryStarted(
            $parentRun,
            $sequence,
            $retryRun,
            $retryLink,
            $failedChildRun,
            $childCallId,
            $parentClosePolicy,
            $attemptCount,
            $backoffSeconds,
            $retryPolicy,
            $timeoutPolicy,
            $executionTimeoutSeconds,
            $runTimeoutSeconds,
            $executionDeadlineAt,
            $runDeadlineAt,
            $parallelMetadata,
            $handedOver,
        );

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()->create([
            'workflow_run_id' => $retryRun->id,
            'namespace' => $retryRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $availableAt,
            'payload' => [],
            'connection' => $retryRun->connection,
            'queue' => $retryRun->queue,
            'compatibility' => $retryRun->compatibility,
        ]);

        ChildRunHistory::markChildCallRetryStarted(
            $parentRun,
            $sequence,
            $retryRun,
            $childCallId,
            $attemptCount,
            $backoffSeconds,
            $failedChildRun,
        );

        TaskDispatcher::dispatch($retryTask);

        return [$retryTask, $retryRun];
    }

    /**
     * @param array<string, mixed>|null $parallelMetadata
     * @return list<array<string, mixed>>|null
     */
    private static function parallelGroupPath(?array $parallelMetadata): ?array
    {
        if ($parallelMetadata === null) {
            return null;
        }

        $path = ParallelChildGroup::metadataPathFromPayload($parallelMetadata);

        return $path === [] ? null : $path;
    }
}
