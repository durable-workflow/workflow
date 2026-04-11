<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonInterval;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use ReflectionMethod;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\WorkflowFiberContext;

if (! function_exists(__NAMESPACE__ . '\\activity')) {
    function activity(string $activity, ...$arguments): mixed
    {
        return WorkflowFiberContext::suspend(new ActivityCall($activity, $arguments));
    }
}

if (! function_exists(__NAMESPACE__ . '\\startActivity')) {
    function startActivity(string $activity, ...$arguments): ActivityCall
    {
        return new ActivityCall($activity, $arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\await')) {
    function await(callable $condition, ?string $conditionKey = null): mixed
    {
        $condition = \Closure::fromCallable($condition);

        return WorkflowFiberContext::suspend(new AwaitCall(
            $condition,
            Support\ConditionWaitKey::normalize($conditionKey),
            Support\ConditionWaitDefinition::fingerprint($condition),
        ));
    }
}

if (! function_exists(__NAMESPACE__ . '\\awaitWithTimeout')) {
    function awaitWithTimeout(
        int|string|CarbonInterval $duration,
        callable $condition,
        ?string $conditionKey = null,
    ): mixed {
        if ($duration instanceof CarbonInterval) {
            $duration = (int) ceil($duration->totalSeconds);
        } elseif (is_string($duration)) {
            $duration = (int) ceil(CarbonInterval::fromString($duration)->totalSeconds);
        }

        $condition = \Closure::fromCallable($condition);

        return WorkflowFiberContext::suspend(new AwaitWithTimeoutCall(
            max(0, $duration),
            $condition,
            Support\ConditionWaitKey::normalize($conditionKey),
            Support\ConditionWaitDefinition::fingerprint($condition),
        ));
    }
}

if (! function_exists(__NAMESPACE__ . '\\child')) {
    function child(string $workflow, ...$arguments): mixed
    {
        return WorkflowFiberContext::suspend(new ChildWorkflowCall($workflow, $arguments));
    }
}

if (! function_exists(__NAMESPACE__ . '\\startChild')) {
    function startChild(string $workflow, ...$arguments): ChildWorkflowCall
    {
        return new ChildWorkflowCall($workflow, $arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\all')) {
    function all(iterable $calls): mixed
    {
        return WorkflowFiberContext::suspend(new AllCall($calls));
    }
}

if (! function_exists(__NAMESPACE__ . '\\parallel')) {
    function parallel(iterable $calls): AllCall
    {
        return new AllCall($calls);
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

if (! function_exists(__NAMESPACE__ . '\\sideEffect')) {
    function sideEffect(callable $callback): mixed
    {
        return WorkflowFiberContext::suspend(new SideEffectCall(\Closure::fromCallable($callback)));
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

if (! function_exists(__NAMESPACE__ . '\\awaitSignal')) {
    function awaitSignal(string $name): mixed
    {
        return WorkflowFiberContext::suspend(new SignalCall($name));
    }
}

if (! function_exists(__NAMESPACE__ . '\\continueAsNew')) {
    function continueAsNew(...$arguments): mixed
    {
        return WorkflowFiberContext::suspend(new ContinueAsNewCall($arguments));
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
