<?php

declare(strict_types=1);

namespace Workflow\Traits;

trait ResolvesStorageConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('workflows.storage.connection') ?? $this->connection;
        if ($connection !== null) {
            return $connection;
        }

        $runModel = config('workflows.v2.run_model');
        if (! is_string($runModel) || $runModel === \Workflow\V2\Models\WorkflowRun::class
            || ! is_subclass_of($runModel, \Workflow\V2\Models\WorkflowRun::class)) {
            return null;
        }

        // Keep legacy storage independent when only v2 models are customized.
        $class = static::class;
        while (! str_starts_with($class, 'Workflow\\V2\\Models\\')) {
            $class = get_parent_class($class);
            if ($class === false) {
                return null;
            }
        }

        // Base-model helpers and models without an override share the run's
        // authoritative storage; explicit model connections still take precedence.
        foreach ((array) config('workflows.v2', []) as $key => $model) {
            if (str_ends_with((string) $key, '_model') && is_string($model)
                && $model !== static::class && is_subclass_of($model, static::class)) {
                return (new $model())->getConnectionName();
            }
        }

        return $runModel !== static::class ? (new $runModel())->getConnectionName() : null;
    }
}
