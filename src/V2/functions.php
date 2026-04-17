<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonInterval;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use ReflectionMethod;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\UpsertMemoCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\WorkflowFiberContext;

if (! function_exists(__NAMESPACE__ . '\\activity')) {
    function activity(string $activity, mixed ...$arguments): mixed
    {
        $options = null;

        if (($arguments[0] ?? null) instanceof ActivityOptions) {
            $options = array_shift($arguments);
        }

        return WorkflowFiberContext::suspend(new ActivityCall($activity, $arguments, $options));
    }
}

if (! function_exists(__NAMESPACE__ . '\\child')) {
    function child(string $workflow, mixed ...$arguments): mixed
    {
        $options = null;

        if (($arguments[0] ?? null) instanceof ChildWorkflowOptions) {
            $options = array_shift($arguments);
        }

        return WorkflowFiberContext::suspend(new ChildWorkflowCall($workflow, $arguments, $options));
    }
}

if (! function_exists(__NAMESPACE__ . '\\async')) {
    function async(callable $callback): mixed
    {
        $reflection = match (true) {
            is_array($callback) => new ReflectionMethod($callback[0], $callback[1]),
            is_string($callback) && str_contains($callback, '::') => new ReflectionMethod($callback),
            is_object($callback) && ! $callback instanceof \Closure => new ReflectionMethod($callback, '__invoke'),
            default => new ReflectionFunction($callback),
        };

        if ($reflection->isGenerator()) {
            throw StraightLineWorkflowRequiredException::forAsyncCallback();
        }

        return child(AsyncWorkflow::class, new SerializableClosure(\Closure::fromCallable($callback)));
    }
}

if (! function_exists(__NAMESPACE__ . '\\all')) {
    function all(iterable $calls): mixed
    {
        $resolved = [];

        foreach ($calls as $call) {
            if ($call instanceof \Closure) {
                $resolved[] = WorkflowFiberContext::whileInactive($call);
            } else {
                $resolved[] = $call;
            }
        }

        return WorkflowFiberContext::suspend(new AllCall($resolved));
    }
}

if (! function_exists(__NAMESPACE__ . '\\await')) {
    function await(callable|string $condition, int|string|CarbonInterval|null $timeout = null, ?string $conditionKey = null): mixed
    {
        if (
            ! is_string($condition)
            && is_string($timeout)
            && $conditionKey === null
            && preg_match('/(^P(T|\d)|\b\d+\s*(microseconds?|milliseconds?|msecs?|ms|seconds?|secs?|s|minutes?|mins?|m|hours?|hrs?|h|days?|d|weeks?|w|months?|years?)\b)/i', $timeout) !== 1
        ) {
            $conditionKey = $timeout;
            $timeout = null;
        }

        $timeoutSeconds = null;
        if ($timeout !== null) {
            if ($timeout instanceof CarbonInterval) {
                $timeoutSeconds = (int) ceil($timeout->totalSeconds);
            } elseif (is_string($timeout)) {
                $timeoutSeconds = (int) ceil(CarbonInterval::fromString($timeout)->totalSeconds);
            } else {
                $timeoutSeconds = $timeout;
            }

            $timeoutSeconds = max(0, $timeoutSeconds);
        }

        if (is_string($condition)) {
            return WorkflowFiberContext::suspend(new SignalCall($condition, $timeoutSeconds));
        }

        $condition = \Closure::fromCallable($condition);

        if ($timeoutSeconds !== null) {
            return WorkflowFiberContext::suspend(new AwaitWithTimeoutCall(
                $timeoutSeconds,
                $condition,
                Support\ConditionWaitKey::normalize($conditionKey),
                Support\ConditionWaitDefinition::fingerprint($condition),
            ));
        }

        return WorkflowFiberContext::suspend(new AwaitCall(
            $condition,
            Support\ConditionWaitKey::normalize($conditionKey),
            Support\ConditionWaitDefinition::fingerprint($condition),
        ));
    }
}

if (! function_exists(__NAMESPACE__ . '\\signal')) {
    /**
     * @deprecated Use await($name) for durable workflow-code signal waits.
     */
    function signal(string $name): mixed
    {
        return await($name);
    }
}

if (! function_exists(__NAMESPACE__ . '\\timer')) {
    function timer(int|string|CarbonInterval $duration): mixed
    {
        if ($duration instanceof CarbonInterval) {
            $duration = (int) ceil($duration->totalSeconds);
        } elseif (is_string($duration)) {
            $duration = (int) ceil(CarbonInterval::fromString($duration)->totalSeconds);
        }

        return WorkflowFiberContext::suspend(new TimerCall(max(0, $duration)));
    }
}

if (! function_exists(__NAMESPACE__ . '\\sideEffect')) {
    function sideEffect(callable $callback): mixed
    {
        return WorkflowFiberContext::suspend(new SideEffectCall(\Closure::fromCallable($callback)));
    }
}

if (! function_exists(__NAMESPACE__ . '\\continueAsNew')) {
    function continueAsNew(...$arguments): mixed
    {
        return WorkflowFiberContext::suspend(new ContinueAsNewCall($arguments));
    }
}

if (! function_exists(__NAMESPACE__ . '\\upsertMemo')) {
    /**
     * Upsert non-indexed memo metadata on the current workflow run.
     *
     * Memos are eventually consistent describe/list metadata, not replay
     * authority and not a safe place for workflow-branching truth. They are
     * excluded from filter and sort semantics by contract.
     *
     * Setting a key to null removes it from the active memo.
     *
     * @param array<string, mixed> $entries
     */
    function upsertMemo(array $entries): void
    {
        WorkflowFiberContext::suspend(new UpsertMemoCall($entries));
    }
}

if (! function_exists(__NAMESPACE__ . '\\upsertSearchAttributes')) {
    /**
     * Upsert indexed search attributes on the current workflow run.
     *
     * Search attributes are typed operator-visible metadata used for filtering,
     * sorting, and saved views. They are plain-text values and must not contain
     * secrets or PII.
     *
     * Setting a key to null removes it from the active search attributes.
     *
     * @param array<string, scalar|null> $attributes
     */
    function upsertSearchAttributes(array $attributes): void
    {
        WorkflowFiberContext::suspend(new UpsertSearchAttributesCall($attributes));
    }
}

if (! function_exists(__NAMESPACE__ . '\\getVersion')) {
    function getVersion(
        string $changeId,
        int $minSupported = WorkflowStub::DEFAULT_VERSION,
        int $maxSupported = 1,
    ): mixed {
        return WorkflowFiberContext::suspend(new VersionCall($changeId, $minSupported, $maxSupported));
    }
}

// Timer sugar

if (! function_exists(__NAMESPACE__ . '\\seconds')) {
    function seconds(int $seconds): mixed
    {
        return timer($seconds);
    }
}

if (! function_exists(__NAMESPACE__ . '\\minutes')) {
    function minutes(int $minutes): mixed
    {
        return timer($minutes * 60);
    }
}

if (! function_exists(__NAMESPACE__ . '\\hours')) {
    function hours(int $hours): mixed
    {
        return timer($hours * 3600);
    }
}

if (! function_exists(__NAMESPACE__ . '\\days')) {
    function days(int $days): mixed
    {
        return timer($days * 86400);
    }
}

if (! function_exists(__NAMESPACE__ . '\\weeks')) {
    function weeks(int $weeks): mixed
    {
        return timer($weeks * 604800);
    }
}

if (! function_exists(__NAMESPACE__ . '\\months')) {
    function months(int $months): mixed
    {
        return timer("{$months} months");
    }
}

if (! function_exists(__NAMESPACE__ . '\\years')) {
    function years(int $years): mixed
    {
        return timer("{$years} years");
    }
}

if (! function_exists(__NAMESPACE__ . '\\now')) {
    /**
     * Read the deterministic workflow time.
     *
     * Inside a workflow fiber, returns the timestamp of the last history
     * event the executor replayed before resuming.
     * Outside a workflow fiber, falls back to wall-clock time.
     */
    function now(): \Carbon\CarbonInterface
    {
        return WorkflowFiberContext::getTime();
    }
}
