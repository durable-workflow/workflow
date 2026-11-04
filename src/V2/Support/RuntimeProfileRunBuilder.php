<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Workflow\V2\Models\WorkflowRun;

/**
 * Scalar Eloquent reads still hydrate a run before accessors/observers.
 * Keep the row identity available to the authoritative hydration guard.
 *
 * @internal
 * @extends Builder<WorkflowRun>
 */
final class RuntimeProfileRunBuilder extends Builder
{
    public function getModels($columns = ['*'])
    {
        $items = $this->query->get($columns)
            ->all();
        return RuntimeProfileGuard::hydrateBatch(
            $this->model,
            $items,
            fn (): array => $this->model->hydrate($items)
                ->all(),
        );
    }

    public function value($column)
    {
        $result = $this->identityScalarQuery($column)
            ->first();
        if ($result !== null) {
            $column = $column instanceof Expression ? $column->getValue($this->getGrammar()) : $column;
            return $result->{Str::afterLast($column, '.')};
        }
    }

    public function soleValue($column)
    {
        $result = $this->identityScalarQuery($column)
            ->sole();
        $column = $column instanceof Expression ? $column->getValue($this->getGrammar()) : $column;
        return $result->{Str::afterLast($column, '.')};
    }

    public function valueOrFail($column)
    {
        $result = $this->identityScalarQuery($column)
            ->firstOrFail();
        $column = $column instanceof Expression ? $column->getValue($this->getGrammar()) : $column;
        return $result->{Str::afterLast($column, '.')};
    }

    private function identityScalarQuery(mixed $column): self
    {
        $query = clone $this;
        $query->addSelect([$column, $this->model->qualifyColumn($this->model->getKeyName()),
            $this->model->qualifyColumn('workflow_instance_id'), $this->model->qualifyColumn('namespace'),
            $this->model->qualifyColumn('runtime_profile')]);
        return $query;
    }
}
