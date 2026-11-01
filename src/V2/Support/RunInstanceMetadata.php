<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

/** @internal Rejects instance metadata inherited through a legacy foreign attachment. */
final class RunInstanceMetadata
{
    public static function detachForeign(WorkflowRun $run): void
    {
        $run->loadMissing('instance');
        if ($run->instance !== null && $run->instance->namespace !== $run->namespace) {
            $run->setRelation('instance', null);
            $run->setRelation('summary', null);
        }
    }

    /**
     * @return array{namespace: ?string, business_key: ?string, visibility_labels: array<mixed>, is_current_run: bool}
     */
    public static function forSummary(WorkflowRunSummary $summary): array
    {
        $metadata = [
            'namespace' => $summary->namespace,
            'business_key' => $summary->business_key,
            'visibility_labels' => is_array($summary->visibility_labels) ? $summary->visibility_labels : [],
            'is_current_run' => (bool) $summary->is_current_run,
        ];
        if (! self::needsOwnershipCheck($summary)) {
            return $metadata;
        }

        // Only old imported summaries need this check. Current projections
        // already use exact instance ownership, keeping ordinary lists cheap.
        $summary->loadMissing('run.instance');
        $run = $summary->run;
        if (! $run instanceof WorkflowRun) {
            return array_replace($metadata, [
                'business_key' => null,
                'visibility_labels' => [],
                'is_current_run' => false,
            ]);
        }
        $instance = $run->instance;
        $ownsInstance = $instance !== null && $instance->namespace === $run->namespace;
        if ($ownsInstance && $summary->workflow_instance_id === $run->workflow_instance_id
            && $summary->namespace === $run->namespace) {
            return $metadata;
        }

        return [
            'namespace' => $run->namespace,
            'business_key' => $run->business_key ?? ($ownsInstance ? $instance?->business_key : null),
            'visibility_labels' => $run->visibility_labels ?? ($ownsInstance ? $instance?->visibility_labels : null) ?? [],
            'is_current_run' => $ownsInstance && (bool) $summary->is_current_run,
        ];
    }

    private static function needsOwnershipCheck(WorkflowRunSummary $summary): bool
    {
        return $summary->exists
            && $summary->engine_source === EmbeddedV2ImportContract::ENGINE_SOURCE
            && (int) $summary->projection_schema_version < RunSummaryProjector::SCHEMA_VERSION;
    }
}
