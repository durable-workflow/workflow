<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class QueryStateReplayer
{
    public function query(WorkflowRun $run, string $method, array $arguments = []): mixed
    {
        $workflow = $this->replay($run);
        $parameters = $workflow->resolveMethodDependencies(
            $arguments,
            new ReflectionMethod($workflow, $method),
        );

        return $workflow->{$method}(...$parameters);
    }

    public function replay(WorkflowRun $run): \Workflow\V2\Workflow
    {
        $run->loadMissing(['instance', 'activityExecutions', 'timers', 'failures', 'commands', 'historyEvents']);

        $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        $workflow = new $workflowClass($run);
        $arguments = $workflow->resolveMethodDependencies(
            $run->workflowArguments(),
            new ReflectionMethod($workflow, 'execute'),
        );
        $result = $workflow->execute(...$arguments);

        if (! $result instanceof Generator) {
            return $workflow;
        }

        $current = $result->current();
        $sequence = 1;

        while (true) {
            if (! $result->valid()) {
                return $workflow;
            }

            if ($current instanceof ActivityCall) {
                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null || in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                    return $workflow;
                }

                if ($execution->status === ActivityStatus::Completed) {
                    $current = $result->send($execution->activityResult());
                } else {
                    $current = $result->throw($this->activityException($execution));
                }

                ++$sequence;

                continue;
            }

            if ($current instanceof TimerCall) {
                $timer = $run->timers->firstWhere('sequence', $sequence);

                if ($timer === null || $timer->status === TimerStatus::Pending) {
                    return $workflow;
                }

                $current = $result->send(true);

                ++$sequence;

                continue;
            }

            if ($current instanceof SignalCall) {
                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent === null) {
                    return $workflow;
                }

                $current = $result->send($this->signalValue($signalEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof ContinueAsNewCall) {
                return $workflow;
            }

            throw new UnsupportedWorkflowYieldException(sprintf(
                'Workflow %s yielded %s. v2 currently supports activity(), timer(), awaitSignal(), and continueAsNew() only.',
                $run->workflow_class,
                get_debug_type($current),
            ));
        }
    }

    private function appliedSignalEvent(
        WorkflowRun $run,
        int $sequence,
        SignalCall $signalCall,
    ): ?WorkflowHistoryEvent {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalApplied
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalCall->name
        );

        return $event;
    }

    private function signalValue(WorkflowHistoryEvent $event): mixed
    {
        $serialized = $event->payload['value'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        return Serializer::unserialize($serialized);
    }

    private function activityException(ActivityExecution $execution): Throwable
    {
        /** @var array{class?: string, message?: string, code?: int} $payload */
        $payload = is_string($execution->exception)
            ? Serializer::unserialize($execution->exception)
            : [];

        return new RuntimeException(
            sprintf('[%s] %s', $payload['class'] ?? RuntimeException::class, $payload['message'] ?? 'Activity failed'),
            (int) ($payload['code'] ?? 0),
        );
    }
}
