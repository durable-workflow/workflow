<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class SelectedRunLocator
{
    /**
     * @param list<string> $relations
     */
    public static function forIdOrFail(string $id, array $relations = []): WorkflowRun
    {
        $run = self::runQuery($relations)->find($id);

        if ($run instanceof WorkflowRun) {
            return $run;
        }

        return self::forInstanceIdOrFail($id, null, $relations);
    }

    /**
     * @param list<string> $relations
     */
    public static function forRunIdOrFail(string $runId, array $relations = []): WorkflowRun
    {
        /** @var WorkflowRun $run */
        $run = self::runQuery($relations)->findOrFail($runId);

        return $run;
    }

    /**
     * @param list<string> $relations
     */
    public static function forInstanceIdOrFail(
        string $instanceId,
        ?string $runId = null,
        array $relations = [],
    ): WorkflowRun {
        $instanceModel = self::instanceModel();

        /** @var WorkflowInstance $instance */
        $instance = $instanceModel::query()->findOrFail($instanceId);

        return self::forInstanceOrFail($instance, $runId, $relations);
    }

    /**
     * @param list<string> $relations
     */
    public static function forInstanceOrFail(
        WorkflowInstance $instance,
        ?string $runId = null,
        array $relations = [],
    ): WorkflowRun {
        if ($runId !== null) {
            /** @var WorkflowRun $run */
            $run = self::runQuery($relations)
                ->where('workflow_instance_id', $instance->id)
                ->whereKey($runId)
                ->firstOrFail();

            return $run;
        }

        $run = CurrentRunResolver::forInstance($instance, $relations);

        if ($run instanceof WorkflowRun) {
            return $run;
        }

        self::throwRunNotFound($instance->id);
    }

    /**
     * @param list<string> $relations
     */
    private static function runQuery(array $relations)
    {
        $runModel = self::runModel();
        $query = $runModel::query();

        if ($relations !== []) {
            $query->with($relations);
        }

        return $query;
    }

    /**
     * @return class-string<WorkflowInstance>
     */
    private static function instanceModel(): string
    {
        /** @var class-string<WorkflowInstance> $model */
        $model = config('workflows.v2.instance_model', WorkflowInstance::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRun>
     */
    private static function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }

    private static function throwRunNotFound(string $instanceId): never
    {
        $exception = new ModelNotFoundException();
        $exception->setModel(self::runModel(), [$instanceId]);

        throw $exception;
    }
}
