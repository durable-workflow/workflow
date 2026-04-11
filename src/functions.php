<?php

declare(strict_types=1);

namespace Workflow;

use Carbon\CarbonInterval;
use React\Promise\PromiseInterface;

if (! function_exists(__NAMESPACE__ . '\\await')) {
    function await($condition): PromiseInterface
    {
        return WorkflowStub::await($condition);
    }
}

if (! function_exists(__NAMESPACE__ . '\\timer')) {
    function timer(int|string|CarbonInterval $seconds): PromiseInterface
    {
        return WorkflowStub::timer($seconds);
    }
}

if (! function_exists(__NAMESPACE__ . '\\awaitWithTimeout')) {
    function awaitWithTimeout(int|string|CarbonInterval $seconds, $condition): PromiseInterface
    {
        return WorkflowStub::awaitWithTimeout($seconds, $condition);
    }
}

if (! function_exists(__NAMESPACE__ . '\\sideEffect')) {
    function sideEffect($callable): PromiseInterface
    {
        return WorkflowStub::sideEffect($callable);
    }
}

if (! function_exists(__NAMESPACE__ . '\\continueAsNew')) {
    function continueAsNew(...$arguments): PromiseInterface
    {
        return WorkflowStub::continueAsNew(...$arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\getVersion')) {
    function getVersion(
        string $changeId,
        int $minSupported = WorkflowStub::DEFAULT_VERSION,
        int $maxSupported = 1
    ): PromiseInterface {
        return WorkflowStub::getVersion($changeId, $minSupported, $maxSupported);
    }
}

if (! function_exists(__NAMESPACE__ . '\\activity')) {
    function activity($activity, ...$arguments): PromiseInterface
    {
        return ActivityStub::make($activity, ...$arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\child')) {
    function child($workflow, ...$arguments): PromiseInterface
    {
        return ChildWorkflowStub::make($workflow, ...$arguments);
    }
}

if (! function_exists(__NAMESPACE__ . '\\all')) {
    function all(iterable $promises): PromiseInterface
    {
        return \React\Promise\all([...$promises]);
    }
}

if (! function_exists(__NAMESPACE__ . '\\async')) {
    function async(callable $callback): PromiseInterface
    {
        return ActivityStub::async($callback);
    }
}

if (! function_exists(__NAMESPACE__ . '\\now')) {
    function now()
    {
        return WorkflowStub::now();
    }
}

if (! function_exists(__NAMESPACE__ . '\\seconds')) {
    function seconds(int $seconds): PromiseInterface
    {
        return WorkflowStub::timer($seconds);
    }
}

if (! function_exists(__NAMESPACE__ . '\\minutes')) {
    function minutes(int $minutes): PromiseInterface
    {
        return WorkflowStub::timer($minutes * 60);
    }
}

if (! function_exists(__NAMESPACE__ . '\\hours')) {
    function hours(int $hours): PromiseInterface
    {
        return WorkflowStub::timer($hours * 3600);
    }
}

if (! function_exists(__NAMESPACE__ . '\\days')) {
    function days(int $days): PromiseInterface
    {
        return WorkflowStub::timer($days * 86400);
    }
}

if (! function_exists(__NAMESPACE__ . '\\weeks')) {
    function weeks(int $weeks): PromiseInterface
    {
        return WorkflowStub::timer($weeks * 604800);
    }
}

if (! function_exists(__NAMESPACE__ . '\\months')) {
    function months(int $months): PromiseInterface
    {
        return WorkflowStub::timer("{$months} months");
    }
}

if (! function_exists(__NAMESPACE__ . '\\years')) {
    function years(int $years): PromiseInterface
    {
        return WorkflowStub::timer("{$years} years");
    }
}

// Keep straight-line V2 helpers available for consumers still autoloading only the root helper file.
require_once __DIR__ . '/V2/functions.php';
