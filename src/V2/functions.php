<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonInterval;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;

if (! function_exists(__NAMESPACE__ . '\\activity')) {
    function activity(string $activity, ...$arguments): ActivityCall
    {
        return new ActivityCall($activity, $arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\child')) {
    function child(string $workflow, ...$arguments): ChildWorkflowCall
    {
        return new ChildWorkflowCall($workflow, $arguments);
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
