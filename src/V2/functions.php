<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonInterval;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\VersionCall;

if (! function_exists(__NAMESPACE__ . '\\activity')) {
    function activity(string $activity, ...$arguments): ActivityCall
    {
        return new ActivityCall($activity, $arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\await')) {
    function await(callable $condition, ?string $conditionKey = null): AwaitCall
    {
        $condition = \Closure::fromCallable($condition);

        return new AwaitCall(
            $condition,
            Support\ConditionWaitKey::normalize($conditionKey),
            Support\ConditionWaitDefinition::fingerprint($condition),
        );
    }
}

if (! function_exists(__NAMESPACE__ . '\\awaitWithTimeout')) {
    function awaitWithTimeout(
        int|string|CarbonInterval $duration,
        callable $condition,
        ?string $conditionKey = null,
    ): AwaitWithTimeoutCall
    {
        if ($duration instanceof CarbonInterval) {
            $duration = (int) ceil($duration->totalSeconds);
        } elseif (is_string($duration)) {
            $duration = (int) ceil(CarbonInterval::fromString($duration)->totalSeconds);
        }

        $condition = \Closure::fromCallable($condition);

        return new AwaitWithTimeoutCall(
            max(0, $duration),
            $condition,
            Support\ConditionWaitKey::normalize($conditionKey),
            Support\ConditionWaitDefinition::fingerprint($condition),
        );
    }
}

if (! function_exists(__NAMESPACE__ . '\\child')) {
    function child(string $workflow, ...$arguments): ChildWorkflowCall
    {
        return new ChildWorkflowCall($workflow, $arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\all')) {
    function all(iterable $calls): AllCall
    {
        return new AllCall($calls);
    }
}

if (! function_exists(__NAMESPACE__ . '\\async')) {
    function async(callable $callback): ChildWorkflowCall
    {
        return child(AsyncWorkflow::class, new SerializableClosure($callback));
    }
}

if (! function_exists(__NAMESPACE__ . '\\sideEffect')) {
    function sideEffect(callable $callback): SideEffectCall
    {
        return new SideEffectCall(\Closure::fromCallable($callback));
    }
}

if (! function_exists(__NAMESPACE__ . '\\timer')) {
    function timer(int|string|CarbonInterval $duration): TimerCall
    {
        if ($duration instanceof CarbonInterval) {
            $duration = (int) ceil($duration->totalSeconds);
        } elseif (is_string($duration)) {
            $duration = (int) ceil(CarbonInterval::fromString($duration)->totalSeconds);
        }

        return new TimerCall(max(0, $duration));
    }
}

if (! function_exists(__NAMESPACE__ . '\\awaitSignal')) {
    function awaitSignal(string $name): SignalCall
    {
        return new SignalCall($name);
    }
}

if (! function_exists(__NAMESPACE__ . '\\continueAsNew')) {
    function continueAsNew(...$arguments): ContinueAsNewCall
    {
        return new ContinueAsNewCall($arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\getVersion')) {
    function getVersion(
        string $changeId,
        int $minSupported = WorkflowStub::DEFAULT_VERSION,
        int $maxSupported = 1,
    ): VersionCall {
        return new VersionCall($changeId, $minSupported, $maxSupported);
    }
}
