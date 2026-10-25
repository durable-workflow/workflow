<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

/** @internal Resolves imported lineage only through a materialized scoped edge. */
final class ImportedLineageReferences
{
    public static function imported(WorkflowRun $run): bool
    {
        return $run->import_source === EmbeddedV2ImportContract::IMPORT_SOURCE;
    }

    public static function allows(WorkflowRun $origin, WorkflowRun $related): bool
    {
        if ($origin->namespace !== $related->namespace) {
            return false;
        }
        if ($origin->id === $related->id || ! self::imported($origin)) {
            return true;
        }

        // Unresolved imported IDs remain history, not authority to attach a
        // later run. The importer writes an edge only after locking and
        // validating both endpoint run/instance pairs in this namespace.
        return ConfiguredV2Models::query('link_model', WorkflowLink::class)
            ->where(static function ($query) use ($origin, $related): void {
                $query->where(static function ($edge) use ($origin, $related): void {
                    $edge->where('parent_workflow_run_id', $origin->id)
                        ->where('parent_workflow_instance_id', $origin->workflow_instance_id)
                        ->where('child_workflow_run_id', $related->id)
                        ->where('child_workflow_instance_id', $related->workflow_instance_id);
                })->orWhere(static function ($edge) use ($origin, $related): void {
                    $edge->where('child_workflow_run_id', $origin->id)
                        ->where('child_workflow_instance_id', $origin->workflow_instance_id)
                        ->where('parent_workflow_run_id', $related->id)
                        ->where('parent_workflow_instance_id', $related->workflow_instance_id);
                });
            })->exists();
    }

    /**
     * @param list<string> $relations
     */
    public static function resolve(WorkflowRun $origin, ?string $id, array $relations = []): ?WorkflowRun
    {
        if ($id === null) {
            return null;
        }
        /** @var WorkflowRun|null $related */
        $related = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('namespace', $origin->namespace)
            ->with($relations)
            ->find($id);

        return $related instanceof WorkflowRun && self::allows($origin, $related) ? $related : null;
    }
}
