<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Fiber;
use LogicException;
use ReflectionFiber;
use Throwable;
use WeakReference;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Exceptions\WorkflowFiberDiscardedException;
use Workflow\V2\Workflow;

final class WorkflowExecution
{
    private function __construct(
        private readonly ?Fiber $fiber = null,
        private mixed $current = null,
        private mixed $return = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function start(
        Workflow $workflow,
        array $arguments,
        ?CarbonInterface $eventTime = null,
        ?int $definitionFingerprintVersion = null,
        bool $allowContinueAsNewTarget = false,
    ): self {
        $entryMethod = EntryMethod::forWorkflow($workflow);
        WorkflowEntryArguments::assertInvocation($workflow, $entryMethod, $arguments);

        return self::startCallback(
            static fn (): mixed => $workflow->{$entryMethod->getName()}(...$arguments),
            straightLineError: StraightLineWorkflowRequiredException::forWorkflow($workflow::class),
            eventTime: $eventTime,
            definitionFingerprintVersion: $definitionFingerprintVersion,
            allowContinueAsNewTarget: $allowContinueAsNewTarget,
        );
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function startCallback(
        callable $callback,
        array $arguments = [],
        ?StraightLineWorkflowRequiredException $straightLineError = null,
        ?CarbonInterface $eventTime = null,
        ?int $definitionFingerprintVersion = null,
        bool $allowContinueAsNewTarget = false,
    ): self {
        $callback = ExecutionTracing::bind($callback);
        $fiber = new Fiber(static function () use ($callback, $arguments): mixed {
            WorkflowFiberContext::enter();

            try {
                return $callback(...$arguments);
            } catch (WorkflowFiberDiscardedException) {
                // No executor observes a result from a force-closed Fiber.
                return null;
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        if ($eventTime !== null) {
            WorkflowFiberContext::setTime($eventTime, $fiber);
        }
        if ($allowContinueAsNewTarget) {
            WorkflowFiberContext::enableContinueAsNewTargets($fiber);
        }

        WorkflowFiberContext::setDefinitionFingerprintVersion(
            $definitionFingerprintVersion ?? SourceFingerprint::recordingVersion(),
            $fiber,
        );

        $sample = WorkflowExecutionProfile::begin('authoring_start');
        try {
            $current = self::checkedYield(WeakReference::create($fiber), $fiber->start());
        } finally {
            WorkflowExecutionProfile::finish($sample);
        }

        if ($fiber->isSuspended()) {
            return new self(fiber: $fiber, current: $current);
        }

        $result = $fiber->getReturn();

        if ($result instanceof \Generator) {
            throw $straightLineError ?? StraightLineWorkflowRequiredException::forCallback();
        }

        return new self(return: $result);
    }

    public function current(): mixed
    {
        return $this->current;
    }

    public function fiber(): ?Fiber
    {
        return $this->fiber;
    }

    public function recordMismatchSource(HistoryEventShapeMismatchException $mismatch): void
    {
        if ($this->fiber === null || ! $this->fiber->isSuspended() || $mismatch->workflowSource() !== null) {
            return;
        }

        $trace = (new ReflectionFiber($this->fiber))->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $runtimeDirectory = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        foreach ($trace as $index => $frame) {
            // A frame gives the location of the call. The next frame names its caller.
            $source = self::sourceFromFrame($frame, $trace[$index + 1] ?? [], $runtimeDirectory);
            if ($source !== null) {
                $mismatch->recordWorkflowSource($source);

                return;
            }
        }
    }

    public function valid(): bool
    {
        if ($this->fiber instanceof Fiber) {
            return ! $this->fiber->isTerminated();
        }

        return false;
    }

    public function send(
        mixed $value,
        ?CarbonInterface $eventTime = null,
        bool $advanceCancellationTime = true,
    ): mixed {
        if (! $this->fiber instanceof Fiber) {
            return null;
        }

        if ($eventTime !== null) {
            WorkflowFiberContext::setTime($eventTime, $this->fiber, $advanceCancellationTime);
        } elseif ($advanceCancellationTime) {
            WorkflowFiberContext::observeCancellationTime(null, $this->fiber);
        }

        WorkflowFiberContext::markResumed($this->fiber);
        $sample = WorkflowExecutionProfile::begin('authoring_resume');
        try {
            $result = self::checkedYield(WeakReference::create($this->fiber), $this->fiber->resume($value));
        } finally {
            WorkflowExecutionProfile::finish($sample);
        }

        if ($this->fiber->isSuspended()) {
            $this->current = $result;

            return $this->current;
        }

        $this->current = null;
        $this->return = $this->fiber->getReturn();

        return null;
    }

    public function throw(
        Throwable $throwable,
        ?CarbonInterface $eventTime = null,
        bool $advanceCancellationTime = true,
    ): mixed {
        if (! $this->fiber instanceof Fiber) {
            throw $throwable;
        }

        if ($throwable instanceof WorkflowCancellationRequestedException && $throwable->cancellation !== null) {
            $throwable->cancellation->bindToReplayFiber($this->fiber);
            WorkflowFiberContext::startCancellationTime($eventTime, $this->fiber);
        }

        if ($eventTime !== null) {
            WorkflowFiberContext::setTime($eventTime, $this->fiber, $advanceCancellationTime);
        } elseif ($advanceCancellationTime) {
            WorkflowFiberContext::observeCancellationTime(null, $this->fiber);
        }

        WorkflowFiberContext::markResumed($this->fiber);
        $sample = WorkflowExecutionProfile::begin('authoring_throw');
        try {
            $result = self::checkedYield(WeakReference::create($this->fiber), $this->fiber->throw($throwable));
        } finally {
            WorkflowExecutionProfile::finish($sample);
        }

        if ($this->fiber->isSuspended()) {
            $this->current = $result;

            return $this->current;
        }

        $this->current = null;
        $this->return = $this->fiber->getReturn();

        return null;
    }

    public function getReturn(): mixed
    {
        return $this->return;
    }

    /**
     * @param array<string, mixed> $frame
     * @param array<string, mixed> $caller
     * @return array{function: string, file: string, line: int}|null
     */
    private static function sourceFromFrame(array $frame, array $caller, string $runtimeDirectory): ?array
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? 0;
        if (! is_string($file) || ! is_int($line) || $line < 1) {
            return null;
        }
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, $runtimeDirectory)) {
            return null;
        }
        $file = basename($file);
        if (! str_ends_with($file, '.php') || preg_match('/[[:cntrl:]]/', $file) !== 0) {
            return null;
        }

        $function = $caller['function'] ?? '';
        if (! is_string($function) || $function === '') {
            return null;
        }
        if (str_contains($function, '{closure')) {
            $function = strstr($function, '{closure', true) . '{closure}';
        }
        $class = $caller['class'] ?? '';
        if (! is_string($class) || str_contains($class, '@anonymous')) {
            $class = '{anonymous class}';
        }

        return [
            'function' => $class === '' ? $function : $class . '::' . $function,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * Refusals go back into the suspended call before the executor can emit it.
     * Fiber destruction does not take this path: its finally blocks must still
     * reach the native force-close refusal, rather than a new business error.
     *
     * @param WeakReference<Fiber> $reference A refusal trace must not retain its own Fiber argument.
     */
    private static function checkedYield(WeakReference $reference, mixed $call): mixed
    {
        $fiber = $reference->get();
        if (! $fiber instanceof Fiber) {
            throw new LogicException('The original workflow Fiber is unavailable.');
        }
        while ($fiber->isSuspended()) {
            try {
                WorkflowFiberContext::checkForwardBudgets(spl_object_id($fiber), $call);
                return $call;
            } catch (Throwable $error) {
                $call = $fiber->throw($error);
            }
        }
        return $call;
    }
}
