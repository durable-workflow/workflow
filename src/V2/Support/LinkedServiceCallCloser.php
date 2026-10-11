<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;

final class LinkedServiceCallCloser
{
    public static function reconcile(WorkflowServiceCall $call): void
    {
        ServiceCallWorkflowDelivery::enqueue($call);
        if ($call->resolved_binding_kind !== ServiceCallBindingKind::WorkflowRun->value
            || ! in_array($call->status, ['pending', 'accepted', 'started'], true)
            || ! is_string($call->linked_workflow_run_id)) {
            return;
        }

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
            ->whereIn('event_type', self::terminalEventTypes())
            ->orderBy('sequence')
            ->first();
        if ($event !== null) {
            self::closeForTerminalEvent($run, $event);
            $call->refresh();
        }
    }

    public static function closeForTerminalEvent(WorkflowRun $run, WorkflowHistoryEvent $event): void
    {
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
            HistoryEventType::WorkflowFailed, HistoryEventType::WorkflowTerminated => [
                ServiceCallStatus::Failed, ServiceCallOutcome::HandlerFailed, 'failed_at',
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
     * @return list<string>
     */
    private static function terminalEventTypes(): array
    {
        return [
            HistoryEventType::WorkflowCompleted->value,
            HistoryEventType::WorkflowFailed->value,
            HistoryEventType::WorkflowCancelled->value,
            HistoryEventType::WorkflowTerminated->value,
        ];
    }
}
