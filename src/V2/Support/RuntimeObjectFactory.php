<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Container\Container;
use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

/**
 * Constructs application-authored runtime objects through Laravel's container.
 *
 * Durable context is deliberately attached after construction. Application
 * workflows and activities can therefore use ordinary constructor injection
 * without accepting engine-owned model arguments in their public constructors.
 *
 * @internal
 */
final class RuntimeObjectFactory
{
    /**
     * @param class-string<Workflow> $workflowClass
     */
    public static function workflow(string $workflowClass, WorkflowRun $run): Workflow
    {
        $workflow = Container::getInstance()->make($workflowClass);

        if (! $workflow instanceof Workflow) {
            throw new LogicException(sprintf(
                'The Laravel container must resolve workflow [%s] to an instance of [%s].',
                $workflowClass,
                Workflow::class,
            ));
        }

        $workflow->bindRuntime($run);

        return $workflow;
    }

    /**
     * @param class-string<Activity> $activityClass
     */
    public static function activity(
        string $activityClass,
        ActivityExecution $execution,
        WorkflowRun $run,
        ?string $taskId = null,
    ): Activity {
        $activity = Container::getInstance()->make($activityClass);

        if (! $activity instanceof Activity) {
            throw new LogicException(sprintf(
                'The Laravel container must resolve activity [%s] to an instance of [%s].',
                $activityClass,
                Activity::class,
            ));
        }

        $activity->bindRuntime($execution, $run, $taskId);

        return $activity;
    }
}
