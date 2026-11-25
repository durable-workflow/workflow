<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use Throwable;
use Workflow\V2\Enums\WorkflowReplayState;
use Workflow\V2\Workflow;

/**
 * Replay-aware workflow logger.
 *
 * Writes through the application's Laravel logger only while workflow code
 * runs beyond its committed history, so each line is written once per
 * committed step instead of once per wake-up. Replays, queries and update
 * validators stay quiet. Every line carries the run's identity.
 *
 * This is a process logger, not an exactly-once outbox: a workflow task that
 * fails before it commits runs its new code, and its log lines, again. Keep
 * log handlers fast and non-blocking; a slow handler stalls the worker.
 * Logger failures are swallowed and never affect the workflow.
 *
 * @api
 */
final class WorkflowLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly Workflow $workflow,
        private readonly ?string $channel = null,
    ) {
    }

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->workflow->replayState() !== WorkflowReplayState::Executing) {
            return;
        }

        try {
            $logger = $this->channel === null ? Log::getFacadeRoot() : Log::channel($this->channel);

            if (! $logger instanceof LoggerInterface) {
                return;
            }

            $logger->log($level, $message, array_merge($context, $this->identity()));
        } catch (Throwable) {
            // Logging is an observation; it must never fail workflow code.
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function identity(): array
    {
        $run = $this->workflow->run;
        $namespace = $run->namespace;

        return [
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
            'namespace' => is_string($namespace) && $namespace !== '' ? $namespace : null,
        ];
    }
}
