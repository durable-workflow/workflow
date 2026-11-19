<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\CommandContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Enums\ExternalSignalOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\WorkflowStub;

/**
 * Admits one durable signal-external command on its target (.github#223).
 *
 * Runs inside the sending workflow task's transaction. A delivered signal is
 * an ordinary signal on the target: the same accepted command, signal record,
 * `SignalReceived` event and message task as any other signal, attributed to
 * the sending run through `CommandContext::workflow()` and keyed on the
 * target as `workflow-signal:<sender run>:<sequence>`. Because the sender's
 * history and the target's admission share one transaction, a task that
 * rolls back sends nothing and a committed task never sends again.
 *
 * @internal
 */
final class ExternalSignalDelivery
{
    /**
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    public static function deliver(WorkflowRun $sender, int $sequence, SignalExternalWorkflowCall $call): array
    {
        $requestId = ExternalSignalHistory::requestId($sender->id, $sequence);
        $identity = [
            'sequence' => $sequence,
            'target_workflow_instance_id' => $call->workflowId,
            'signal_name' => $call->signalName,
            'request_id' => $requestId,
        ];

        if ($call->workflowId === $sender->workflow_instance_id) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::Rejected,
                'self_target',
                null,
                'A workflow cannot signal its own instance as a durable signal-external command.'
            );
        }

        if ($call->signalName === WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::Rejected,
                'runtime_reserved_signal',
                null,
                'The signal name is reserved for the runtime message stream.'
            );
        }

        /** @var WorkflowInstance|null $instance */
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->find($call->workflowId);

        // Exact identity in the sender's namespace: a collation-folded match
        // or a workflow in another namespace is not this target.
        if (! $instance instanceof WorkflowInstance
            || $instance->id !== $call->workflowId
            || NamespaceScope::canonical(self::stringValue($instance->namespace))
                !== NamespaceScope::canonical(self::stringValue($sender->namespace))) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::TargetNotFound,
                'instance_not_found',
                null,
                sprintf('No workflow instance [%s] exists in this namespace.', $call->workflowId)
            );
        }

        if ($call->runId !== null) {
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($call->runId);

            if (! $run instanceof WorkflowRun
                || $run->id !== $call->runId
                || $run->workflow_instance_id !== $instance->id) {
                return self::failed(
                    $identity,
                    null,
                    ExternalSignalOutcome::TargetNotFound,
                    'run_not_found',
                    null,
                    sprintf('Workflow instance [%s] has no run [%s].', $instance->id, $call->runId)
                );
            }
        }

        $result = WorkflowStub::loadSelection($instance->id, $call->runId)
            ->withCommandContext(CommandContext::workflow($sender->workflow_instance_id, $sender->id, $sequence))
            ->attemptExternalWorkflowSignal($requestId, $call->signalName, $call->arguments);

        return self::resolution($identity, $call, $result);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    private static function resolution(array $identity, SignalExternalWorkflowCall $call, CommandResult $result): array
    {
        if ($result->accepted()) {
            /** @var WorkflowSignal|null $signal */
            $signal = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
                ->where('workflow_command_id', $result->commandId())
                ->first();

            return [
                'event_type' => HistoryEventType::ExternalSignalDelivered,
                'payload' => [
                    ...$identity,
                    'target_workflow_run_id' => $result->runId(),
                    'outcome' => ExternalSignalOutcome::Delivered->value,
                    'target_command_id' => $result->commandId(),
                    'target_signal_id' => $signal?->id,
                    'retained' => $result->retained(),
                ],
            ];
        }

        $reason = $result->rejectionReason() ?? 'rejected';
        // A key conflict refuses against the retained command and records
        // nothing new on the target.
        $targetCommandId = $result->requestIdConflict() ? null : $result->commandId();
        $targetRunId = $result->requestIdConflict() ? null : $result->runId();

        [$outcome, $reason, $message] = match ($reason) {
            'instance_not_started' => [
                ExternalSignalOutcome::TargetNotFound,
                'instance_not_found',
                sprintf('Workflow instance [%s] has not started.', $call->workflowId),
            ],
            'run_not_active' => [
                ExternalSignalOutcome::TargetClosed,
                'run_not_active',
                'The target run is closed.',
            ],
            'selected_run_not_current' => [
                ExternalSignalOutcome::TargetClosed,
                'historical_run',
                'The requested run has been replaced by a newer run of the target instance.',
            ],
            default => [
                ExternalSignalOutcome::Rejected,
                $reason,
                $result->message() ?? sprintf('The target refused the signal: %s.', $reason),
            ],
        };

        return self::failed($identity, $targetRunId, $outcome, $reason, $targetCommandId, $message);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    private static function failed(
        array $identity,
        ?string $targetRunId,
        ExternalSignalOutcome $outcome,
        string $reason,
        ?string $targetCommandId,
        string $message,
    ): array {
        return [
            'event_type' => HistoryEventType::ExternalSignalFailed,
            'payload' => [
                ...$identity,
                'target_workflow_run_id' => $targetRunId,
                'outcome' => $outcome->value,
                'reason' => $reason,
                'message' => $message,
                'target_command_id' => $targetCommandId,
            ],
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
