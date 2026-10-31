<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
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
        if ($lockForUpdate && self::instanceHasNoRuns($instance)) {
            self::attachResolvedRun($instance, null);

            return [
                'run' => null,
                'source' => null,
            ];
        }

        $lineageRunId = self::lineageResolvedRunId($instance, $lockForUpdate);
        $run = $lineageRunId === null
            ? null
            : self::findRunById($instance, $lineageRunId, $relations, $lockForUpdate);
        $source = $run instanceof WorkflowRun
            ? self::SOURCE_CONTINUE_AS_NEW_LINEAGE
            : null;

        if (! $run instanceof WorkflowRun) {
            $run = $lockForUpdate
                ? self::queryForInstance($instance, $relations, true)->first()
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

        if (! $run->instance instanceof WorkflowInstance || ! self::ownsRun($run->instance, $run)) {
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

        if (! $run->instance instanceof WorkflowInstance || ! self::ownsRun($run->instance, $run)) {
            return [
                'run' => null,
                'source' => null,
            ];
        }

        return self::resolutionForInstance($run->instance, $relations, $lockForUpdate);
    }

    public static function syncPointer(WorkflowInstance $instance, ?WorkflowRun $run): void
    {
        if ($run instanceof WorkflowRun && ! self::ownsRun($instance, $run)) {
            throw new LogicException('workflow_instance_current_run_mismatch');
        }

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
        if (! $lockForUpdate && $instance->relationLoaded('runs')) {
            /** @var WorkflowRun|null $loaded */
            $loaded = $instance->runs->first(static fn (WorkflowRun $candidate): bool =>
                $candidate->id === $runId && self::ownsRun($instance, $candidate));

            $loaded?->loadMissing($relations);

            return $loaded;
        }

        $query = self::ownedRunsQuery($instance)
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
                $instance->runs->filter(static fn (mixed $candidate): bool =>
                    $candidate instanceof WorkflowRun && self::ownsRun($instance, $candidate))
            )
                ->first();

            $run?->loadMissing($relations);

            return $run;
        }

        return self::queryForInstance($instance, $relations)->first();
    }

    private static function instanceHasNoRuns(WorkflowInstance $instance): bool
    {
        return ($instance->current_run_id ?? null) === null
            && (int) ($instance->run_count ?? 0) === 0;
    }

    private static function ownsRun(WorkflowInstance $instance, WorkflowRun $run): bool
    {
        return $run->workflow_instance_id === $instance->id && $run->namespace === $instance->namespace;
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

        $runIds = $runs->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();

        if ($runIds === []) {
            return null;
        }

        $runIdLookup = array_fill_keys($runIds, true);
        $successors = [];

        ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
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
        if (! $lockForUpdate && $instance->relationLoaded('runs')) {
            /** @var EloquentCollection<int, WorkflowRun> $runs */
            $runs = $instance->runs
                ->filter(static fn (mixed $candidate): bool =>
                    $candidate instanceof WorkflowRun && self::ownsRun($instance, $candidate))
                ->values();

            return $runs;
        }

        /** @var EloquentCollection<int, WorkflowRun> $runs */
        $runs = self::ownedRunsQuery($instance)
            ->select(['id', 'workflow_instance_id', 'namespace', 'run_number', 'started_at', 'created_at'])
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
        WorkflowInstance $instance,
        array $relations = [],
        bool $lockForUpdate = false,
    ) {
        $query = self::ownedRunsQuery($instance)
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

    /**
     * @return Builder<Model>
     */
    private static function ownedRunsQuery(WorkflowInstance $instance): Builder
    {
        $query = ConfiguredV2Models::query('run_model', WorkflowRun::class);
        $column = $query->getModel()
            ->qualifyColumn('namespace');
        $query->where('workflow_instance_id', $instance->id)
            ->where($column, $instance->namespace);
        if ($instance->namespace === null) {
            return $query;
        }

        // Namespace IDs use the same exact identity as the PHP ownership
        // checks, even when the database's default collation folds case.
        $column = $query->getQuery()
            ->getGrammar()
            ->wrap($column);
        $comparison = match ($query->getQuery()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST({$column} AS BINARY) = CAST(? AS BINARY)",
            'sqlite' => "CAST({$column} AS BLOB) = CAST(? AS BLOB)",
            'pgsql' => "CAST({$column} AS TEXT) COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\"",
            'sqlsrv' => "CONVERT(varbinary(max), {$column}) = CONVERT(varbinary(max), CAST(? AS nvarchar(max)))",
            default => null,
        };
        if ($comparison !== null) {
            $query->whereRaw($comparison, [$instance->namespace]);
        }

        return $query;
    }
}
