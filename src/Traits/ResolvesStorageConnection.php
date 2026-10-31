<?php

declare(strict_types=1);

namespace Workflow\Traits;

trait ResolvesStorageConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('workflows.storage.connection') ?? $this->connection;

        return $connection ?? $this->customV2StorageConnection();
    }

    private function customV2StorageConnection(): ?string
    {
        $runModel = config('workflows.v2.run_model');
        if (! is_string($runModel) || $runModel === \Workflow\V2\Models\WorkflowRun::class
            || ! is_subclass_of($runModel, \Workflow\V2\Models\WorkflowRun::class)
            || ! $this->isV2StorageModel()) {
            return null;
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

    private function isV2StorageModel(): bool
    {
        // Keep legacy storage independent when only v2 models are customized.
        $class = static::class;
        do {
            if (str_starts_with($class, 'Workflow\\V2\\Models\\')) {
                return true;
            }
            $class = get_parent_class($class);
        } while ($class !== false);

        return false;
    }
}
