<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class CurrentRunResolver
{
    public const SOURCE_CONTINUE_AS_NEW_LINEAGE = 'continue_as_new_lineage';

    public const SOURCE_RUN_ORDER_FALLBACK = 'run_order_fallback';

    /**
     * @param list<string> $relations
     */
    public static function forInstance(
        WorkflowInstance $instance,
        array $relations = [],
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        return self::resolutionForInstance($instance, $relations, $lockForUpdate)['run'];
    }

    /**
     * @param list<string> $relations
     * @return array{run: ?WorkflowRun, source: ?string}
     */
    public static function resolutionForInstance(
        WorkflowInstance $instance,
        array $relations = [],
        bool $lockForUpdate = false,
    ): array {
        $lineageRunId = self::lineageResolvedRunId($instance, $lockForUpdate);
        $run = $lineageRunId === null
            ? null
            : self::findRunById($instance, $lineageRunId, $relations, $lockForUpdate);
        $source = $run instanceof WorkflowRun
            ? self::SOURCE_CONTINUE_AS_NEW_LINEAGE
            : null;

        if (! $run instanceof WorkflowRun) {
            $run = $lockForUpdate
                ? self::queryForInstance($instance->id, $relations, true)->first()
                : self::loadedOrQueriedRun($instance, $relations);
            $source = $run instanceof WorkflowRun
                ? self::SOURCE_RUN_ORDER_FALLBACK
                : null;
        }

        self::attachResolvedRun($instance, $run);

        return [
            'run' => $run,
            'source' => $source,
        ];
    }

    /**
     * @param list<string> $relations
     */
    public static function forRun(
        WorkflowRun $run,
        array $relations = [],
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        $run->loadMissing('instance');

        if (! $run->instance instanceof WorkflowInstance) {
            return null;
        }

        return self::forInstance($run->instance, $relations, $lockForUpdate);
    }

    /**
     * @param list<string> $relations
     * @return array{run: ?WorkflowRun, source: ?string}
     */
    public static function resolutionForRun(
        WorkflowRun $run,
        array $relations = [],
        bool $lockForUpdate = false,
    ): array {
        $run->loadMissing('instance');

        if (! $run->instance instanceof WorkflowInstance) {
            return [
                'run' => null,
                'source' => null,
            ];
        }

        return self::resolutionForInstance($run->instance, $relations, $lockForUpdate);
    }

    public static function syncPointer(WorkflowInstance $instance, ?WorkflowRun $run): void
    {
        $resolvedRunId = $run?->id;
        $resolvedRunCount = $run?->run_number ?? 0;
        $currentRunCount = (int) ($instance->run_count ?? 0);

        if ($instance->current_run_id === $resolvedRunId && $currentRunCount >= $resolvedRunCount) {
            self::attachResolvedRun($instance, $run);

            return;
        }

        $instance->forceFill([
            'current_run_id' => $resolvedRunId,
            'run_count' => max($currentRunCount, $resolvedRunCount),
        ])->save();

        self::attachResolvedRun($instance, $run);
    }

    /**
     * @param list<string> $relations
     */
    private static function findRunById(
        WorkflowInstance $instance,
        string $runId,
        array $relations = [],
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        if ($instance->relationLoaded('runs')) {
            /** @var WorkflowRun|null $loaded */
            $loaded = $instance->runs->first(
                static fn (WorkflowRun $candidate): bool => $candidate->id === $runId
            );

            $loaded?->loadMissing($relations);

            return $loaded;
        }

        $query = WorkflowRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->whereKey($runId);

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var WorkflowRun|null $run */
        $run = $query->first();

        return $run;
    }

    /**
     * @param list<string> $relations
     */
    private static function loadedOrQueriedRun(WorkflowInstance $instance, array $relations): ?WorkflowRun
    {
        if ($instance->relationLoaded('runs')) {
            /** @var WorkflowRun|null $run */
            $run = self::orderedRuns(
                $instance->runs->filter(static fn (mixed $candidate): bool => $candidate instanceof WorkflowRun)
            )
                ->first();

            $run?->loadMissing($relations);

            return $run;
        }

        return self::queryForInstance($instance->id, $relations)->first();
    }

    private static function attachResolvedRun(WorkflowInstance $instance, ?WorkflowRun $run): void
    {
        $instance->setRelation('currentRun', $run);

        if ($run instanceof WorkflowRun) {
            $run->setRelation('instance', $instance);
        }
    }

    private static function lineageResolvedRunId(WorkflowInstance $instance, bool $lockForUpdate = false): ?string
    {
        $runs = self::instanceRunsForLineage($instance, $lockForUpdate);

        if ($runs->count() < 2) {
            return null;
        }

        $runIds = $runs->pluck('id')->filter(static fn (mixed $id): bool => is_string($id))->values()->all();

        if ($runIds === []) {
            return null;
        }

        $runIdLookup = array_fill_keys($runIds, true);
        $successors = [];

        WorkflowHistoryEvent::query()
            ->whereIn('workflow_run_id', $runIds)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->get(['workflow_run_id', 'payload'])
            ->each(static function (WorkflowHistoryEvent $event) use (&$successors, $runIdLookup): void {
                $continuedFromRunId = self::continuedFromRunId($event);

                if ($continuedFromRunId === null || ! isset($runIdLookup[$continuedFromRunId])) {
                    return;
                }

                $successors[$continuedFromRunId] = $event->workflow_run_id;
            });

        if ($successors === []) {
            return null;
        }

        $tailIds = array_values(array_diff(array_values($successors), array_keys($successors)));

        if ($tailIds === []) {
            return null;
        }

        $tailLookup = array_fill_keys($tailIds, true);
        $tailRuns = $runs
            ->filter(static fn (WorkflowRun $candidate): bool => isset($tailLookup[$candidate->id]))
            ->values();

        /** @var WorkflowRun|null $resolved */
        $resolved = self::orderedRuns($tailRuns)->first();

        return $resolved?->id;
    }

    /**
     * @return EloquentCollection<int, WorkflowRun>
     */
    private static function instanceRunsForLineage(
        WorkflowInstance $instance,
        bool $lockForUpdate = false,
    ): EloquentCollection {
        if ($instance->relationLoaded('runs')) {
            /** @var EloquentCollection<int, WorkflowRun> $runs */
            $runs = $instance->runs
                ->filter(static fn (mixed $candidate): bool => $candidate instanceof WorkflowRun)
                ->values();

            return $runs;
        }

        /** @var EloquentCollection<int, WorkflowRun> $runs */
        $runs = WorkflowRun::query()
            ->select(['id', 'workflow_instance_id', 'run_number', 'started_at', 'created_at'])
            ->where('workflow_instance_id', $instance->id)
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->get();

        return $runs;
    }

    private static function continuedFromRunId(WorkflowHistoryEvent $event): ?string
    {
        return is_array($event->payload)
            && is_string($event->payload['continued_from_run_id'] ?? null)
            && $event->payload['continued_from_run_id'] !== ''
                ? $event->payload['continued_from_run_id']
                : null;
    }

    /**
     * @param EloquentCollection<int, WorkflowRun> $runs
     * @return EloquentCollection<int, WorkflowRun>
     */
    private static function orderedRuns(EloquentCollection $runs): EloquentCollection
    {
        return $runs->sort(static fn (WorkflowRun $left, WorkflowRun $right): int => self::compareRuns($left, $right));
    }

    private static function compareRuns(WorkflowRun $left, WorkflowRun $right): int
    {
        if ($left->run_number !== $right->run_number) {
            return $right->run_number <=> $left->run_number;
        }

        $leftStartedAt = $left->started_at?->getTimestampMs()
            ?? $left->created_at?->getTimestampMs()
            ?? 0;
        $rightStartedAt = $right->started_at?->getTimestampMs()
            ?? $right->created_at?->getTimestampMs()
            ?? 0;

        if ($leftStartedAt !== $rightStartedAt) {
            return $rightStartedAt <=> $leftStartedAt;
        }

        $leftCreatedAt = $left->created_at?->getTimestampMs() ?? 0;
        $rightCreatedAt = $right->created_at?->getTimestampMs() ?? 0;

        if ($leftCreatedAt !== $rightCreatedAt) {
            return $rightCreatedAt <=> $leftCreatedAt;
        }

        return $right->id <=> $left->id;
    }

    /**
     * @param list<string> $relations
     */
    private static function queryForInstance(
        string $instanceId,
        array $relations = [],
        bool $lockForUpdate = false,
    ) {
        $query = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->orderByDesc('run_number')
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query;
    }
}
