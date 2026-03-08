<?php

declare(strict_types=1);

namespace Workflow;

use function React\Promise\all;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use RuntimeException;
use Throwable;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowFailedStatus;

final class ChildWorkflowStub
{
    public static function all(iterable $promises): PromiseInterface
    {
        return all([...$promises]);
    }

    public static function make($workflow, ...$arguments): PromiseInterface
    {
        $context = WorkflowStub::getContext();

        $log = $context->storedWorkflow->findLogByIndex($context->index);

        if (WorkflowStub::faked()) {
            $mocks = WorkflowStub::mocks();

            if (! $log && array_key_exists($workflow, $mocks)) {
                $result = $mocks[$workflow];

                $log = $context->storedWorkflow->createLog([
                    'index' => $context->index,
                    'now' => $context->now,
                    'class' => $workflow,
                    'result' => Serializer::serialize(
                        is_callable($result) ? $result($context, ...$arguments) : $result
                    ),
                ]);

                WorkflowStub::recordDispatched($workflow, $arguments);
            }
        }

        if ($log) {
            ++$context->index;
            WorkflowStub::setContext($context);
            return resolve(Serializer::unserialize($log->result));
        }

        if (! $context->replaying) {
            $storedChildWorkflow = $context->storedWorkflow->children()
                ->wherePivot('parent_index', $context->index)
                ->first();

            if ($storedChildWorkflow && $storedChildWorkflow->status::class === WorkflowFailedStatus::class) {
                ++$context->index;
                WorkflowStub::setContext($context);
                $childException = $storedChildWorkflow->exceptions()
                    ->latest()
                    ->first();
                if ($childException) {
                    $exceptionData = Serializer::unserialize($childException->exception);
                    if (
                        is_array($exceptionData)
                        && array_key_exists('class', $exceptionData)
                        && is_subclass_of($exceptionData['class'], Throwable::class)
                    ) {
                        try {
                            throw new $exceptionData['class'](
                                $exceptionData['message'] ?? '',
                                (int) ($exceptionData['code'] ?? 0)
                            );
                        } catch (Throwable $throwable) {
                            throw new RuntimeException(
                                sprintf('[%s] %s', $exceptionData['class'], (string) ($exceptionData['message'] ?? '')),
                                (int) ($exceptionData['code'] ?? 0),
                                $throwable
                            );
                        }
                    }
                }
                throw new RuntimeException('Child workflow ' . $workflow . ' failed');
            }

            $childWorkflow = $storedChildWorkflow ? $storedChildWorkflow->toWorkflow() : WorkflowStub::make($workflow);

            $hasOptions = collect($arguments)
                ->contains(static fn ($argument): bool => $argument instanceof WorkflowOptions);

            if (! $hasOptions) {
                $options = $context->storedWorkflow->workflowOptions();

                if ($options->connection !== null || $options->queue !== null) {
                    $arguments[] = $options;
                }
            }

            if ($childWorkflow->running() && ! $childWorkflow->created()) {
                try {
                    $childWorkflow->resume();
                } catch (TransitionNotFound) {
                    // already running
                }
            } elseif (! $childWorkflow->completed()) {
                $childWorkflow->startAsChild($context->storedWorkflow, $context->index, $context->now, ...$arguments);
            }
        }

        ++$context->index;
        WorkflowStub::setContext($context);
        $deferred = new Deferred();
        return $deferred->promise();
    }
}
