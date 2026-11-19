<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\ExternalSignalOutcome;

/**
 * The recorded outcome of a durable workflow-to-workflow signal.
 *
 * A failed delivery (missing or closed target, or a refusal by the target)
 * is a recorded value, not an exception: workflow code decides what to do.
 * Replay returns this same value from history without sending again.
 *
 * @experimental Durable signal-external may change in a minor release.
 * See docs/api-stability.md#durable-signal-external.
 */
final class ExternalSignalResult
{
    public function __construct(
        public readonly ExternalSignalOutcome $outcome,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly string $targetWorkflowId,
        public readonly ?string $targetRunId,
        public readonly string $signalName,
        public readonly string $requestId,
        public readonly ?string $targetCommandId,
        public readonly ?string $targetSignalId,
        public readonly bool $retained,
    ) {
    }

    public function delivered(): bool
    {
        return $this->outcome === ExternalSignalOutcome::Delivered;
    }

    /**
     * @return array{
     *     outcome: string,
     *     reason: string|null,
     *     message: string|null,
     *     target_workflow_id: string,
     *     target_run_id: string|null,
     *     signal_name: string,
     *     request_id: string,
     *     target_command_id: string|null,
     *     target_signal_id: string|null,
     *     retained: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
            'message' => $this->message,
            'target_workflow_id' => $this->targetWorkflowId,
            'target_run_id' => $this->targetRunId,
            'signal_name' => $this->signalName,
            'request_id' => $this->requestId,
            'target_command_id' => $this->targetCommandId,
            'target_signal_id' => $this->targetSignalId,
            'retained' => $this->retained,
        ];
    }
}
