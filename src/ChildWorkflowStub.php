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
            $result = Serializer::unserialize($log->result);
            if (
                is_array($result)
                && array_key_exists('class', $result)
                && is_subclass_of($result['class'], Throwable::class)
            ) {
                if (! $context->replaying) {
                    $storedChildWorkflow = $context->storedWorkflow->children()
                        ->wherePivot('parent_index', $context->index)
                        ->first();
                    if ($storedChildWorkflow && $storedChildWorkflow->status::class !== WorkflowFailedStatus::class) {
                        $log->delete();
                        $log = null;
                    }
                }

                if ($log) {
                    $context->storedWorkflow->logs()
                        ->where('index', '>', $context->index)
                        ->where('class', Exception::class)
                        ->delete();

                    ++$context->index;
                    WorkflowStub::setContext($context);
                    try {
                        $throwable = new $result['class']($result['message'] ?? '', (int) ($result['code'] ?? 0));
                    } catch (Throwable $throwable) {
                        throw new RuntimeException(
                            sprintf('[%s] %s', $result['class'], (string) ($result['message'] ?? '')),
                            (int) ($result['code'] ?? 0),
                            $throwable
                        );
                    }
                    throw $throwable;
                }
            } else {
                ++$context->index;
                WorkflowStub::setContext($context);
                return resolve($result);
            }
        }

        if (! $context->replaying) {
            $storedChildWorkflow = $context->storedWorkflow->children()
                ->wherePivot('parent_index', $context->index)
                ->first();

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
