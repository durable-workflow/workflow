<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;

final class ServiceCallWorkflowDelivery
{
    public static function forAdmission(WorkflowRun $run, WorkflowHistoryEvent $event): void
    {
        if ($event->event_type !== HistoryEventType::ServiceCallStarted
            || ! is_string($event->payload['service_call_id'] ?? null)) {
            return;
        }

        /** @var WorkflowServiceCall|null $call */
        $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->where('caller_namespace', $run->namespace)
            ->where('caller_workflow_instance_id', $run->workflow_instance_id)
            ->where('caller_workflow_run_id', $run->id)
            ->find($event->payload['service_call_id']);
        if ($call !== null) {
            self::enqueue($call);
        }
    }

    public static function enqueue(WorkflowServiceCall $call): void
    {
        if (! self::isTerminal($call)
            || ! is_string($call->caller_workflow_instance_id)
            || ! is_string($call->caller_workflow_run_id)) {
            return;
        }

        $call->getConnection()
            ->transaction(static function () use ($call): void {
                /** @var WorkflowServiceCall $locked */
                $locked = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->lockForUpdate()
                    ->findOrFail($call->id);
                if (! self::isTerminal($locked)) {
                    return;
                }

                // Stage durable work without locking the other run. Claiming it
                // records the delivery under that caller's own task/run locks.
                /** @var WorkflowRun|null $caller */
                $caller = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->where('namespace', $locked->caller_namespace)
                    ->where('workflow_instance_id', $locked->caller_workflow_instance_id)
                    ->find($locked->caller_workflow_run_id);
                if ($caller === null || $caller->status->isTerminal()) {
                    return;
                }

                $startedEvents = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                    ->where('workflow_run_id', $caller->id)
                    ->where('event_type', HistoryEventType::ServiceCallStarted->value)
                    ->where('payload->service_call_id', $locked->id)
                    ->orderBy('sequence')
                    ->get();
                foreach ($startedEvents as $started) {
                    if (! $started instanceof WorkflowHistoryEvent) {
                        continue;
                    }
                    $payload = $started->payload ?? [];
                    $surface = is_array($payload['service_call'] ?? null) ? $payload['service_call'] : [];
                    if (! is_int($payload['sequence'] ?? null)
                        || ($payload['operation_mode'] ?? null) === 'async'
                        || ($payload['wait_for'] ?? null) === 'accepted'
                        || ($surface['operation_mode'] ?? null) === 'async'
                        || ($surface['wait_for'] ?? null) === 'accepted') {
                        continue;
                    }

                    $existingTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                        ->where('workflow_run_id', $caller->id)
                        ->where('task_type', TaskType::Workflow->value)
                        ->where('payload->resume_source_kind', 'service_call')
                        ->where('payload->service_call_id', $locked->id)
                        ->where('payload->workflow_sequence', $payload['sequence'])
                        ->exists();
                    if ($existingTask || self::terminalEvent($caller, $locked->id, $payload['sequence']) !== null) {
                        continue;
                    }

                    /** @var WorkflowTask $task */
                    $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->create([
                        'workflow_run_id' => $caller->id,
                        'namespace' => $caller->namespace,
                        'task_type' => TaskType::Workflow->value,
                        'status' => TaskStatus::Ready->value,
                        'available_at' => now(),
                        'payload' => [
                            'resume_source_kind' => 'service_call',
                            'service_call_id' => $locked->id,
                            'service_call_terminal_pending' => true,
                            'service_call_started_history_event_id' => $started->id,
                            'workflow_sequence' => $payload['sequence'],
                        ],
                        'connection' => $caller->connection,
                        'queue' => $caller->queue,
                        'compatibility' => $caller->compatibility,
                    ]);
                    TaskDispatcher::dispatch($task);
                }
            });
    }

    public static function recordForClaim(WorkflowTask $task, WorkflowRun $run): void
    {
        $taskPayload = $task->payload ?? [];
        if (($taskPayload['service_call_terminal_pending'] ?? null) !== true
            || ($taskPayload['resume_source_kind'] ?? null) !== 'service_call') {
            return;
        }
        if (! is_string($taskPayload['service_call_id'] ?? null)
            || ! is_string($taskPayload['service_call_started_history_event_id'] ?? null)
            || ! is_int($taskPayload['workflow_sequence'] ?? null)
            || $task->workflow_run_id !== $run->id
            || $task->namespace !== $run->namespace
            || $task->task_type !== TaskType::Workflow
            || $task->status !== TaskStatus::Leased) {
            throw new RuntimeException('Nexus result delivery does not match the leased caller task.');
        }

        /** @var WorkflowRun $lockedCaller */
        $lockedCaller = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->lockForUpdate()
            ->findOrFail($run->id);
        if ($lockedCaller->status->isTerminal()) {
            return;
        }

        /** @var WorkflowServiceCall|null $call */
        $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->where('caller_namespace', $run->namespace)
            ->where('caller_workflow_instance_id', $run->workflow_instance_id)
            ->where('caller_workflow_run_id', $run->id)
            ->find($taskPayload['service_call_id']);
        /** @var WorkflowHistoryEvent|null $started */
        $started = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)
            ->find($taskPayload['service_call_started_history_event_id']);
        if ($call === null || ! self::isTerminal($call) || $started === null
            || ($started->payload['service_call_id'] ?? null) !== $call->id
            || ($started->payload['sequence'] ?? null) !== ($taskPayload['workflow_sequence'] ?? null)) {
            throw new RuntimeException('Nexus result delivery does not match the original call and admission history.');
        }

        $eventType = match ($call->status) {
            'completed' => HistoryEventType::ServiceCallCompleted,
            'cancelled' => HistoryEventType::ServiceCallCancelled,
            default => HistoryEventType::ServiceCallFailed,
        };
        $event = self::terminalEvent($run, $call->id, $taskPayload['workflow_sequence'])
            ?? WorkflowHistoryEvent::record($run, $eventType, self::terminalPayload($call, $started), $task);
        unset($taskPayload['service_call_terminal_pending']);
        $taskPayload['workflow_event_type'] = $event->event_type->value;
        $taskPayload['workflow_history_event_id'] = $event->id;
        $task->forceFill([
            'payload' => $taskPayload,
        ])->save();
    }

    private static function isTerminal(WorkflowServiceCall $call): bool
    {
        return in_array($call->status, ['completed', 'failed', 'cancelled'], true);
    }

    private static function terminalEvent(WorkflowRun $run, string $callId, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::ServiceCallCompleted->value,
                HistoryEventType::ServiceCallFailed->value,
                HistoryEventType::ServiceCallCancelled->value,
            ])
            ->where('payload->service_call_id', $callId)
            ->where('payload->sequence', $sequence)
            ->first();

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private static function terminalPayload(WorkflowServiceCall $call, WorkflowHistoryEvent $started): array
    {
        $payload = $started->payload ?? [];
        unset($payload['task'], $payload['command']);
        $failure = is_array($call->outcome_metadata) ? $call->outcome_metadata : [];
        $surface = array_replace(is_array($payload['service_call'] ?? null) ? $payload['service_call'] : [], [
            'service_call_id' => $call->id,
            'status' => $call->status,
            'outcome' => $call->outcome?->value,
            'outcome_category' => $call->outcome_category,
            'outcome_reason' => $call->outcome_reason,
            'outcome_message' => $call->outcome_message,
            'outcome_metadata' => $failure,
            'failure_message' => $call->failure_message,
            'service_error_type' => $failure['service_error_type'] ?? null,
            'caller_observed_error_type' => $failure['caller_observed_error_type'] ?? null,
            'typed_error_message' => $failure['typed_error_message'] ?? null,
            'resolved_binding_kind' => $call->resolved_binding_kind,
            'resolved_target_reference' => $call->resolved_target_reference,
            'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
            'linked_workflow_run_id' => $call->linked_workflow_run_id,
            'linked_workflow_update_id' => $call->linked_workflow_update_id,
            'completed_at' => $call->completed_at?->toJSON(),
            'failed_at' => $call->failed_at?->toJSON(),
            'cancelled_at' => $call->cancelled_at?->toJSON(),
            'result_envelope' => ServiceCallResultEnvelope::fromCall($call),
        ]);
        $payload = array_replace($payload, [
            'status' => $call->status,
            'outcome' => $call->outcome?->value,
            'response_payload' => $surface['result_envelope'],
            'service_call' => $surface,
            'response_or_failure_surface' => $surface,
        ]);
        if ($call->status !== 'completed') {
            $type = $call->status === 'cancelled'
                ? 'service_call_cancelled'
                : ($failure['caller_observed_error_type'] ?? $failure['service_error_type'] ?? 'service_operation_failed');
            $message = $failure['typed_error_message'] ?? $call->failure_message ?? 'Service operation failed.';
            $payload = array_replace($payload, [
                'exception_type' => $type,
                'exception_class' => RuntimeException::class,
                'message' => $message,
                'code' => 0,
                'exception' => [
                    'class' => RuntimeException::class,
                    'type' => $type,
                    'message' => $message,
                    'code' => 0,
                ],
            ]);
        }

        return $payload;
    }
}
