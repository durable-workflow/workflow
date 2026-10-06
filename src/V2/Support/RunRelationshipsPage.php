<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class RunRelationshipsPage
{
    /**
     * Read declared links and related run metadata without child histories or
     * projection audits. The ceiling bounds link membership, not later changes
     * to a related run's live status.
     *
     * @return array<string, mixed>
     */
    public static function forRun(
        WorkflowRun $run,
        string $direction = 'children',
        int $limit = 50,
        string $afterLinkId = '',
        ?string $throughLinkId = null,
    ): array {
        if (! in_array($direction, ['parents', 'children'], true)
            || $limit < 1 || $limit > 100 || strlen($afterLinkId) > 26
            || ($throughLinkId !== null && strlen($throughLinkId) > 26)) {
            throw new InvalidArgumentException(
                'Relationship pages require parents or children, a limit from 1 to 100 and link IDs up to 26 bytes.'
            );
        }

        $ownSide = $direction === 'children' ? 'parent' : 'child';
        $relatedSide = $direction === 'children' ? 'child' : 'parent';
        /** @var class-string<WorkflowLink> $linkModel */
        $linkModel = ConfiguredV2Models::resolve('link_model', WorkflowLink::class);
        $query = (new $linkModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])
            ->where($ownSide . '_workflow_run_id', $run->id)
            ->where($ownSide . '_workflow_instance_id', $run->workflow_instance_id);
        $throughLinkId ??= (string) ((clone $query)->max('id') ?? '');
        $rows = $query->where('id', '>', $afterLinkId)
            ->where('id', '<=', $throughLinkId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get([
                'id', 'link_type', 'sequence', 'is_primary_parent', 'parent_close_policy',
                $relatedSide . '_workflow_instance_id', $relatedSide . '_workflow_run_id',
            ]);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        /** @var class-string<WorkflowRun> $runModel */
        $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $relatedRuns = (new $runModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])
            ->whereKey($rows->pluck($relatedSide . '_workflow_run_id')->unique()->all())
            ->where('namespace', $run->namespace)
            ->get([
                'id', 'workflow_instance_id', 'namespace', 'workflow_class', 'workflow_type',
                'status', 'closed_reason', 'closed_at', 'details_pruned_at',
            ])->keyBy('id');
        $relationships = $rows->map(static fn (WorkflowLink $link): array => self::relationship(
            $link,
            $relatedSide,
            $relatedRuns,
        ))->values()
            ->all();
        $lastId = $relationships === [] ? null : $relationships[array_key_last($relationships)]['link_id'];

        return [
            'instance_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $run->namespace,
            'direction' => $direction,
            'source' => 'workflow_links',
            'status_source' => 'workflow_runs',
            'history_audit' => 'not_evaluated',
            'relationships' => $relationships,
            'returned_count' => count($relationships),
            'limit' => $limit,
            'after_link_id' => $afterLinkId,
            'through_link_id' => $throughLinkId,
            'has_more' => $hasMore,
            'next_link_id' => $hasMore ? $lastId : null,
            'details_state' => $run->details_pruned_at === null ? 'retained' : 'pruned',
        ];
    }

    /**
     * @param Collection<string, WorkflowRun> $runs
     * @return array<string, mixed>
     */
    private static function relationship(WorkflowLink $link, string $side, Collection $runs): array
    {
        $runId = $link->getAttribute($side . '_workflow_run_id');
        $instanceId = $link->getAttribute($side . '_workflow_instance_id');
        $relatedRun = $runs->get($runId);
        if (! $relatedRun instanceof WorkflowRun || $relatedRun->workflow_instance_id !== $instanceId) {
            $relatedRun = null;
        }

        return [
            'link_id' => $link->id,
            'link_type' => $link->link_type,
            'sequence' => $link->sequence,
            'is_primary_parent' => (bool) $link->is_primary_parent,
            'parent_close_policy' => $link->parent_close_policy,
            'instance_id' => $instanceId,
            'run_id' => $runId,
            'class' => $relatedRun?->workflow_class,
            'workflow_type' => $relatedRun?->workflow_type,
            'status' => $relatedRun?->status?->value,
            'is_terminal' => $relatedRun?->status?->isTerminal(),
            'closed_reason' => $relatedRun?->closed_reason,
            'closed_at' => $relatedRun?->closed_at?->toIso8601String(),
            'metadata_state' => $relatedRun instanceof WorkflowRun ? 'available' : 'unavailable',
            'details_state' => $relatedRun instanceof WorkflowRun
                ? ($relatedRun->details_pruned_at === null ? 'retained' : 'pruned') : 'unavailable',
        ];
    }
}
