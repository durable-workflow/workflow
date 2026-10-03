<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Canonical scope opening under an exact issued workflow claim. */
final class PortableCancellationScopeOpening
{
    /**
     * @return array<string, mixed>
     */
    public static function open(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $parentScopeId,
        bool $shieldParent,
        string $protocolVersion,
    ): array {
        $refused = static fn (string $reason): array => [
            'opened' => false,
            'duplicate' => false,
            'claim_released' => false,
            'task_id' => $taskId,
            'created_task_ids' => [],
            'reason' => $reason,
        ];
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            return $refused('cancellation_scope_requires_protocol_1_20');
        }
        if ($workflowTaskAttempt < 1 || $sequence < 1 || $leaseOwner === '' || trim($parentScopeId) === ''
            || strlen($parentScopeId) > 255 || preg_match('//u', $parentScopeId) !== 1) {
            return $refused('invalid_cancellation_scope_open');
        }
        /** @var WorkflowTask|null $snapshot */
        $snapshot = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);
        if ($snapshot === null) {
            return $refused('task_not_found');
        }
        try {
            return $snapshot->getConnection()
                ->transaction(static function () use (
                    $snapshot,
                    $taskId,
                    $leaseOwner,
                    $workflowTaskAttempt,
                    $sequence,
                    $parentScopeId,
                    $shieldParent,
                    $protocolVersion,
                    $refused
                ): array {
                    // Match the canonical opening kernel's run-before-task order.
                    /** @var WorkflowRun|null $run */
                    $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()
                        ->find($snapshot->workflow_run_id);
                    if ($run === null) {
                        return $refused('run_not_found');
                    }
                    /** @var WorkflowTask|null $claim */
                    $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find(
                        $taskId
                    );
                    if ($claim === null || $claim->workflow_run_id !== $run->id || $claim->namespace !== $run->namespace
                        || $claim->task_type !== TaskType::Workflow || $claim->status !== TaskStatus::Leased
                        || $claim->lease_owner !== $leaseOwner || $claim->attempt_count !== $workflowTaskAttempt
                        || $claim->lease_expires_at === null || now()
                            ->gte($claim->lease_expires_at)) {
                        return $refused('cancellation_scope_workflow_claim_mismatch');
                    }
                    $duplicate = $run->historyEvents()
                        ->where('event_type', HistoryEventType::CancellationScopeOpened)
                        ->where('payload->sequence', $sequence)
                        ->exists();
                    $event = CancellationScopeHistory::open(
                        $run,
                        $claim,
                        $sequence,
                        $protocolVersion,
                        $parentScopeId,
                        $shieldParent
                    );

                    return [
                        'opened' => true,
                        'duplicate' => $duplicate,
                        'claim_released' => false,
                        'task_id' => $taskId,
                        'workflow_run_id' => $run->id,
                        'history_event_id' => $event->id,
                        'scope_id' => $event->payload['scope_id'],
                        'parent_scope_id' => $event->payload['parent_scope_id'],
                        'shield_parent' => $event->payload['shield_parent'],
                        'sequence' => $event->payload['sequence'],
                        'created_task_ids' => [],
                        'reason' => null,
                    ];
                }, 3);
        } catch (HistoryEventShapeMismatchException) {
            return $refused('cancellation_scope_replay_mismatch');
        } catch (LogicException $exception) {
            if (! in_array($exception->getMessage(), [
                'cancellation_scope_workflow_claim_mismatch', 'cancellation_scope_run_not_active',
                'cancellation_scope_parent_not_recorded', 'cancellation_scope_sequence_mismatch',
                'cancellation_scope_history_invalid',
            ], true)) {
                throw $exception;
            }
            return $refused($exception->getMessage());
        }
    }
}
