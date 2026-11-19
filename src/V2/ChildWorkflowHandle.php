<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Support\SignalExternalWorkflowCall;

final class ChildWorkflowHandle
{
    public function __construct(
        private readonly string $workflowInstanceId,
        private readonly ?string $workflowRunId,
        private readonly ?string $childCallId,
        private readonly bool $commandDispatchEnabled,
    ) {
    }

    public function __call(string $method, array $arguments): ?CommandResult
    {
        return $this->signalWithArguments($method, $arguments);
    }

    public function id(): string
    {
        return $this->workflowInstanceId;
    }

    public function instanceId(): string
    {
        return $this->workflowInstanceId;
    }

    /**
     * Stable logical workflow identifier for app-owned projections.
     *
     * Alias of {@see instanceId()} using the workflow authoring name.
     *
     * @api Stable v2 child workflow handle identity API.
     */
    public function workflowId(): string
    {
        return $this->instanceId();
    }

    /**
     * Stable run identifier for the child execution generation, when known.
     *
     * @api Stable v2 child workflow handle identity API.
     */
    public function runId(): ?string
    {
        return $this->workflowRunId;
    }

    public function callId(): ?string
    {
        return $this->childCallId;
    }

    public function signal(string $name, ...$arguments): ?CommandResult
    {
        return $this->signalWithArguments($name, $arguments);
    }

    /**
     * Durably signal this child from the parent's workflow code.
     *
     * Unlike {@see signal()}, which sends directly, this is the durable
     * signal-external command: it is recorded once in the parent's history and
     * replay returns the recorded {@see ExternalSignalResult} without sending
     * again. It targets the child's current run.
     *
     * @experimental Durable signal-external may change in a minor release.
     * See docs/api-stability.md#durable-signal-external.
     */
    public function signalDurably(string $name, mixed ...$arguments): ExternalSignalResult|SignalExternalWorkflowCall
    {
        return signalExternalWorkflow($this->workflowInstanceId, $name, $arguments);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public function signalWithArguments(string $name, array $arguments): ?CommandResult
    {
        if (! $this->commandDispatchEnabled) {
            return null;
        }

        return WorkflowStub::load($this->workflowInstanceId)
            ->attemptSignalWithArguments($name, $arguments);
    }
}
