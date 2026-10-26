<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
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
        if ($origin->namespace !== $related->namespace || $origin->id === $related->id) {
            return false;
        }
        if (! self::imported($origin)) {
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
     * Imported child effects require the directed edge and its typed call identity.
     * Mutating callers retain their endpoint locks and request the edge/history locks.
     */
    public static function allowsChild(
        WorkflowRun $parent,
        WorkflowRun $child,
        ?int $sequence,
        ?string $callId = null,
        bool $lock = false,
    ): bool {
        if ($parent->namespace !== $child->namespace || $parent->id === $child->id) {
            return false;
        }
        if (! self::imported($parent) && ! self::imported($child)) {
            return true;
        }
        if ($sequence === null || $sequence < 1) {
            return false;
        }
        $edges = ConfiguredV2Models::query('link_model', WorkflowLink::class)
            ->where('link_type', 'child_workflow')
            ->where('sequence', $sequence)
            ->where('parent_workflow_run_id', $parent->id)
            ->where('parent_workflow_instance_id', $parent->workflow_instance_id)
            ->where('child_workflow_run_id', $child->id)
            ->where('child_workflow_instance_id', $child->workflow_instance_id);
        if ($lock) {
            $edges->lockForUpdate();
        }
        $links = $edges->get();
        if ($links->isEmpty()) {
            return false;
        }
        $history = $parent->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::ChildWorkflowScheduled->value, HistoryEventType::ChildRunStarted->value,
            ])->where('payload->sequence', $sequence);
        if ($lock) {
            $history->lockForUpdate();
        }
        foreach ($history->get() as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }
            $payload = $event->payload;
            $recordedCall = $payload['child_call_id'] ?? $payload['workflow_link_id'] ?? null;
            if (($payload['sequence'] ?? null) !== $sequence
                || ($payload['child_workflow_run_id'] ?? null) !== $child->id
                || ($payload['child_workflow_instance_id'] ?? null) !== $child->workflow_instance_id
                || ! is_string($recordedCall) || $recordedCall === ''
                || ($callId !== null && $recordedCall !== $callId)) {
                continue;
            }
            foreach ($links as $link) {
                // Exported logical child edges retain the call ID; a live
                // retry/continuation edge can instead have its own link ID.
                if ($link->parent_workflow_run_id === $parent->id
                    && $link->parent_workflow_instance_id === $parent->workflow_instance_id
                    && $link->child_workflow_run_id === $child->id
                    && $link->child_workflow_instance_id === $child->workflow_instance_id
                    && ($link->id === $recordedCall || $link->id === ($payload['workflow_link_id'] ?? null))) {
                    return true;
                }
            }
        }

        return false;
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
