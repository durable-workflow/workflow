<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class QueryStateReplayer
{
    public function query(WorkflowRun $run, string $method, array $arguments = []): mixed
    {
        $workflow = $this->replay($run);
        $parameters = $workflow->resolveMethodDependencies($arguments, new ReflectionMethod($workflow, $method));

        return $workflow->{$method}(...$parameters);
    }

    public function replay(WorkflowRun $run): \Workflow\V2\Workflow
    {
        return $this->replayState($run)
->workflow;
    }

    public function replayState(WorkflowRun $run): ReplayState
    {
        $run->loadMissing([
            'instance',
            'activityExecutions',
            'timers',
            'failures',
            'commands',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
        ]);

        $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        $workflow = new $workflowClass($run);
        $arguments = $workflow->resolveMethodDependencies(
            $run->workflowArguments(),
            new ReflectionMethod($workflow, 'execute'),
        );
        $result = $workflow->execute(...$arguments);

        if (! $result instanceof Generator) {
            return new ReplayState($workflow, 0, null);
        }

        $current = $result->current();
        $sequence = 1;

        while (true) {
            if (! $result->valid()) {
                return new ReplayState($workflow, $sequence, null);
            }

            if ($current instanceof ActivityCall) {
                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null || in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true
                )) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);

                    return new ReplayState($workflow, $sequence, $current);
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
                    $this->applyRecordedUpdates($run, $workflow, $sequence);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send(true);

                ++$sequence;

                continue;
            }

            if ($current instanceof SignalCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent === null) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send($this->signalValue($signalEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $childLink = $this->childLinkForSequence($run, $sequence);
                $childRun = $childLink?->childRun;

                if ($childRun === null || in_array($childRun->status, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                if ($childRun->status === RunStatus::Completed) {
                    $current = $result->send($childRun->workflowOutput());
                } else {
                    $current = $result->throw($this->childException($childRun));
                }

                ++$sequence;

                continue;
            }

            if ($current instanceof ContinueAsNewCall) {
                return new ReplayState($workflow, $sequence, $current);
            }

            throw new UnsupportedWorkflowYieldException(sprintf(
                'Workflow %s yielded %s. v2 currently supports activity(), child(), timer(), awaitSignal(), and continueAsNew() only.',
                $run->workflow_class,
                get_debug_type($current),
            ));
        }
    }

    private function childLinkForSequence(WorkflowRun $run, int $sequence): ?WorkflowLink
    {
        /** @var WorkflowLink|null $link */
        $link = $run->childLinks
            ->filter(
                static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow'
                    && $link->sequence === $sequence
            )
            ->sort(static function (WorkflowLink $left, WorkflowLink $right): int {
                $leftRunNumber = $left->childRun?->run_number ?? 0;
                $rightRunNumber = $right->childRun?->run_number ?? 0;

                if ($leftRunNumber !== $rightRunNumber) {
                    return $rightRunNumber <=> $leftRunNumber;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? 0;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? 0;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $rightCreatedAt <=> $leftCreatedAt;
                }

                return $right->id <=> $left->id;
            })
            ->first();

        return $link;
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

    private function childException(WorkflowRun $childRun): Throwable
    {
        /** @var WorkflowFailure|null $failure */
        $failure = $childRun->failures->first();

        if ($failure !== null) {
            return new RuntimeException(sprintf('[%s] %s', $failure->exception_class, $failure->message));
        }

        return new RuntimeException(sprintf(
            'Child workflow %s closed as %s.',
            $childRun->id,
            $childRun->status->value,
        ));
    }

    private function applyRecordedUpdates(
        WorkflowRun $run,
        \Workflow\V2\Workflow $workflow,
        int $sequence,
    ): void {
        $events = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::UpdateApplied
                    && ($event->payload['sequence'] ?? null) === $sequence
            )
            ->sortBy('sequence');

        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $event->workflow_command_id === null
                ? null
                : $run->commands->firstWhere('id', $event->workflow_command_id);

            $method = $this->updateMethodName($event, $command);

            if ($method === null) {
                throw new LogicException(sprintf(
                    'Workflow update event [%s] is missing an update method name.',
                    $event->id,
                ));
            }

            $arguments = $this->updateArguments($event, $command);
            $parameters = $workflow->resolveMethodDependencies(
                $arguments,
                new ReflectionMethod($workflow, $method),
            );

            $workflow->{$method}(...$parameters);
        }
    }

    private function updateMethodName(WorkflowHistoryEvent $event, ?WorkflowCommand $command): ?string
    {
        $method = $event->payload['update_name'] ?? $command?->targetName();

        return is_string($method) && $method !== ''
            ? $method
            : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function updateArguments(WorkflowHistoryEvent $event, ?WorkflowCommand $command): array
    {
        $serialized = $event->payload['arguments'] ?? null;

        if (is_string($serialized)) {
            $arguments = Serializer::unserialize($serialized);

            return is_array($arguments)
                ? array_values($arguments)
                : [];
        }

        return $command?->payloadArguments() ?? [];
    }
}
