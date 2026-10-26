<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;

final class LinkedServiceCallCloser
{
    private const CONTINUATION_HOPS_PER_PASS = 32;

    public static function reconcile(WorkflowServiceCall $call): void
    {
        if (in_array($call->status, ['pending', 'accepted', 'started'], true)) {
            ServiceCallDeadline::expire($call);
        }
        ServiceCallWorkflowDelivery::enqueue($call);
        if ($call->resolved_binding_kind !== ServiceCallBindingKind::WorkflowRun->value
            || ! in_array($call->status, ['pending', 'accepted', 'started'], true)
            || ! is_string($call->linked_workflow_run_id)) {
            return;
        }

        for ($hop = 0; $hop < self::CONTINUATION_HOPS_PER_PASS; $hop++) {
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->where('namespace', $call->target_namespace)
                ->where('workflow_instance_id', $call->linked_workflow_instance_id)
                ->find($call->linked_workflow_run_id);
            if ($run === null) {
                return;
            }
            /** @var WorkflowHistoryEvent|null $event */
            $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                ->where('workflow_run_id', $run->id)
                ->whereIn(
                    'event_type',
                    [...self::terminalEventTypes(), HistoryEventType::WorkflowContinuedAsNew->value]
                )
                ->orderBy('sequence')
                ->first();
            if ($event === null) {
                return;
            }
            self::closeForTerminalEvent($run, $event);
            $call->refresh();
            if (! in_array($call->status, ['pending', 'accepted', 'started'], true)
                || $call->linked_workflow_run_id === $run->id) {
                return;
            }
        }
    }

    public static function closeForTerminalEvent(WorkflowRun $run, WorkflowHistoryEvent $event): void
    {
        if ($event->workflow_run_id !== $run->id) {
            return;
        }
        if ($event->event_type === HistoryEventType::WorkflowContinuedAsNew) {
            self::advanceForContinuation($run, $event);
            return;
        }
        $terminal = match ($event->event_type) {
            HistoryEventType::WorkflowCompleted => [
                ServiceCallStatus::Completed,
                ServiceCallOutcome::Completed,
                'completed_at',
            ],
            HistoryEventType::WorkflowCancelled => [
                ServiceCallStatus::Cancelled,
                ServiceCallOutcome::Cancelled,
                'cancelled_at',
            ],
            HistoryEventType::WorkflowFailed, HistoryEventType::WorkflowTerminated, HistoryEventType::WorkflowTimedOut => [
                ServiceCallStatus::Failed,
                ServiceCallOutcome::HandlerFailed,
                'failed_at',
            ],
            default => null,
        };
        if ($terminal === null || $event->workflow_run_id !== $run->id) {
            return;
        }

        ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->getModel()
            ->getConnection()
            ->transaction(static function () use ($run, $event, $terminal): void {
                $calls = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->where('target_namespace', $run->namespace)
                    ->where('linked_workflow_instance_id', $run->workflow_instance_id)
                    ->where('linked_workflow_run_id', $run->id)
                    ->where('resolved_binding_kind', ServiceCallBindingKind::WorkflowRun->value)
                    ->whereIn('status', ['pending', 'accepted', 'started'])
                    ->lockForUpdate()
                    ->get();

                foreach ($calls as $call) {
                    if (! $call instanceof WorkflowServiceCall) {
                        continue;
                    }
                    if (ServiceCallDeadline::expireLocked($call, now())) {
                        continue;
                    }
                    [$status, $outcome, $timestamp] = $terminal;
                    $payload = $event->payload ?? [];
                    $metadata = is_array($call->metadata) ? $call->metadata : [];
                    $metadata['linked_terminal_event_id'] = $event->id;
                    $values = [
                        'status' => $status->value,
                        'outcome' => $outcome->value,
                        'outcome_category' => $outcome->category(),
                        'outcome_reason' => $outcome->value,
                        $timestamp => $event->recorded_at,
                    ];

                    if ($status === ServiceCallStatus::Completed) {
                        $codec = $payload['payload_codec'] ?? $run->output_payload_codec ?? $call->payload_codec;
                        $output = $payload['output'] ?? null;
                        if (is_string($output)) {
                            $metadata['nexus_result'] = [
                                'codec' => $codec,
                                'blob' => $output,
                            ];
                        } elseif (is_array($output) && is_array($output['external_storage'] ?? null)) {
                            $reference = $output['external_storage'];
                            $values['output_payload_reference'] = $reference['uri'];
                            unset($reference['uri']);
                            $metadata['nexus_result'] = [
                                'codec' => $codec,
                                'external_reference' => $reference,
                            ];
                        }
                        $values['payload_codec'] = $codec;
                    } else {
                        $failureType = $payload['exception_type'] ?? $payload['exception_class'] ?? $outcome->value;
                        $message = $payload['message'] ?? $payload['reason'] ?? $outcome->value;
                        $values['failure_message'] = $message;
                        $values['outcome_message'] = $message;
                        $values['outcome_metadata'] = [
                            'service_error_type' => $failureType,
                            'caller_observed_error_type' => $failureType,
                            'typed_error_message' => $message,
                        ];
                    }
                    $values['metadata'] = $metadata;
                    $call->forceFill($values)
                        ->save();
                    ServiceCallWorkflowDelivery::enqueue($call);
                }
            });
    }

    /**
     * Catch up long chains without making an API read traverse unbounded history.
     * @return array{candidates: int, reconciled: int, failures: list<array{call_id: string, message: string}>}
     */
    public static function repairDue(
        int $limit,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null
    ): array {
        $query = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->where('resolved_binding_kind', ServiceCallBindingKind::WorkflowRun->value)
            ->whereIn('status', ['pending', 'accepted', 'started'])
            ->whereHas('linkedRun', static function ($run): void {
                $run->whereHas('historyEvents', static function ($history): void {
                    $history->whereIn(
                        'event_type',
                        [...self::terminalEventTypes(), HistoryEventType::WorkflowContinuedAsNew->value]
                    );
                });
            });
        if ($runIds !== []) {
            $query->where(static function ($scope) use ($runIds): void {
                $scope->whereIn('caller_workflow_run_id', $runIds)
                    ->orWhereIn('linked_workflow_run_id', $runIds);
            });
        }
        if ($instanceId !== null) {
            $query->where(static function ($scope) use ($instanceId): void {
                $scope->where('caller_workflow_instance_id', $instanceId)
                    ->orWhere('linked_workflow_instance_id', $instanceId);
            });
        }
        if ($connection !== null || $queue !== null) {
            $runScope = static function ($run) use ($connection, $queue): void {
                if ($connection !== null) {
                    $run->where('connection', $connection);
                }
                if ($queue !== null) {
                    $run->where('queue', $queue);
                }
            };
            $query->where(static function ($scope) use ($runScope): void {
                $scope->whereHas('callerRun', $runScope)
                    ->orWhereHas('linkedRun', $runScope);
            });
        }
        $calls = $query->orderBy('updated_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
        $report = [
            'candidates' => $calls->count(),
            'reconciled' => 0,
            'failures' => [],
        ];
        foreach ($calls as $call) {
            if (! $call instanceof WorkflowServiceCall) {
                continue;
            }
            try {
                $before = [$call->status, $call->linked_workflow_run_id];
                self::reconcile($call);
                if ($before !== [$call->status, $call->linked_workflow_run_id]) {
                    $report['reconciled']++;
                }
            } catch (Throwable $failure) {
                report($failure);
                $report['failures'][] = [
                    'call_id' => $call->id,
                    'message' => $failure->getMessage(),
                ];
            }
        }
        return $report;
    }

    private static function advanceForContinuation(WorkflowRun $run, WorkflowHistoryEvent $event): void
    {
        $nextId = $event->payload['continued_to_run_id'] ?? null;
        if (! is_string($nextId) || $nextId === '') {
            return;
        }
        /** @var WorkflowRun|null $next */
        $next = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('namespace', $run->namespace)
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('workflow_type', $run->workflow_type)
            ->where('run_number', $run->run_number + 1)
            ->find($nextId);
        if ($next === null || ($event->payload['continued_to_run_number'] ?? null) !== $next->run_number) {
            return;
        }
        ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->getModel()
            ->getConnection()
            ->transaction(static function () use ($run, $next, $event): void {
                $calls = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->where('target_namespace', $run->namespace)
                    ->where('linked_workflow_instance_id', $run->workflow_instance_id)
                    ->where('linked_workflow_run_id', $run->id)
                    ->where('resolved_binding_kind', ServiceCallBindingKind::WorkflowRun->value)
                    ->whereIn('status', ['pending', 'accepted', 'started'])
                    ->lockForUpdate()
                    ->get();
                foreach ($calls as $call) {
                    if (! $call instanceof WorkflowServiceCall || ServiceCallDeadline::expireLocked($call, now())) {
                        continue;
                    }
                    $metadata = is_array($call->metadata) ? $call->metadata : [];
                    $metadata['linked_initial_workflow_run_id'] ??= $run->id;
                    $metadata['linked_continuation_event_id'] = $event->id;
                    $call->forceFill([
                        'linked_workflow_run_id' => $next->id,
                        'resolved_target_reference' => $next->id,
                        'metadata' => $metadata,
                    ])->save();
                }
            });
    }

    /**
     * @return list<string>
     */
    private static function terminalEventTypes(): array
    {
        return [
            HistoryEventType::WorkflowCompleted->value,
            HistoryEventType::WorkflowFailed->value,
            HistoryEventType::WorkflowCancelled->value,
            HistoryEventType::WorkflowTerminated->value,
            HistoryEventType::WorkflowTimedOut->value,
        ];
    }
}
