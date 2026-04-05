<?php

declare(strict_types=1);

namespace Workflow;

use function React\Promise\all;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;

final class ChildWorkflowStub
{
    public static function all(iterable $promises): PromiseInterface
    {
        return all([...$promises]);
    }

    public static function make($workflow, ...$arguments): PromiseInterface
    {
        $context = WorkflowStub::getContext();
        $result = null;

        while (true) {
            $log = $context->storedWorkflow->findLogByIndex($context->index);
            $result = null;

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

            if (! $log) {
                break;
            }

            if ($log->class !== Exception::class) {
                break;
            }

            $result = Serializer::unserialize($log->result);

            if (! self::isForeignExceptionResult($result, $workflow)) {
                break;
            }

            ++$context->index;
            WorkflowStub::setContext($context);
        }

        if ($log) {
            $result ??= Serializer::unserialize($log->result);

            if (
                WorkflowStub::isProbing()
                && WorkflowStub::probeIndex() === $context->index
                && (
                    WorkflowStub::probeClass() === null
                    || WorkflowStub::probeClass() === $workflow
                )
                && $log->class === Exception::class
            ) {
                WorkflowStub::markProbeMatched();
            }

            ++$context->index;
            WorkflowStub::setContext($context);
            if (
                is_array($result)
                && array_key_exists('class', $result)
                && is_subclass_of($result['class'], Throwable::class)
            ) {
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
            return resolve($result);
        }

        if (WorkflowStub::isProbing()) {
            WorkflowStub::markProbePendingBeforeMatch();
            ++$context->index;
            WorkflowStub::setContext($context);
            return (new Deferred())->promise();
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

            $runningStartedChildWorkflow = $childWorkflow->running() && ! $childWorkflow->created();

            if (! $runningStartedChildWorkflow && ! $childWorkflow->completed()) {
                $childWorkflow->startAsChild($context->storedWorkflow, $context->index, $context->now, ...$arguments);
            }
        }

        ++$context->index;
        WorkflowStub::setContext($context);
        return (new Deferred())->promise();
    }

    private static function isForeignExceptionResult(mixed $result, string $workflow): bool
    {
        return is_array($result)
            && isset($result['sourceClass'])
            && is_string($result['sourceClass'])
            && $result['sourceClass'] !== $workflow;
    }
}
