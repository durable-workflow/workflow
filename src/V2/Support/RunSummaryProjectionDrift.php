<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class RunSummaryProjectionDrift
{
    /**
     * @return array{runs: int, summaries: int, missing: int, orphaned: int, stale: int, schema_outdated: int, needs_rebuild: int}
     */
    public static function metrics(): array
    {
        $runModel = self::runModel();
        $summaryModel = self::summaryModel();
        $missing = self::missingRunQuery()->count();
        $orphaned = self::orphanedSummaryQuery()->count();
        $stale = self::staleSummaryQuery()->count();
        $schemaOutdated = self::schemaOutdatedQuery()->count();

        return [
            'runs' => $runModel::query()->count(),
            'summaries' => $summaryModel::query()->count(),
            'missing' => $missing,
            'orphaned' => $orphaned,
            'stale' => $stale,
            'schema_outdated' => $schemaOutdated,
            'needs_rebuild' => $missing + $orphaned + $stale + $schemaOutdated,
        ];
    }

    /**
     * Summaries projected under an older (or unknown) schema version.
     *
     * These rows were written by a projector that did not populate the
     * current derived-field set and should be re-projected so that
     * visibility filters, saved views, and fleet search work correctly
     * across mixed-fleet deployments.
     */
    public static function schemaOutdatedQuery(array $runIds = [], ?string $instanceId = null): Builder
    {
        $summaryModel = self::summaryModel();
        $currentVersion = RunSummaryProjector::SCHEMA_VERSION;

        $query = $summaryModel::query()
            ->where(static function ($q) use ($currentVersion): void {
                $q->whereNull('projection_schema_version')
                    ->orWhere('projection_schema_version', '<', $currentVersion);
            });

        if ($runIds !== []) {
            $query->whereIn('id', $runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query;
    }

    public static function missingRunQuery(): Builder
    {
        $runModel = self::runModel();
        $summaryModel = self::summaryModel();

        return $runModel::query()
            ->whereNotIn('id', $summaryModel::query()->select('id'));
    }

    public static function orphanedSummaryQuery(): Builder
    {
        $runModel = self::runModel();
        $summaryModel = self::summaryModel();

        return $summaryModel::query()
            ->whereNotIn('id', $runModel::query()->select('id'));
    }

    /**
     * @param list<string> $runIds
     */
    public static function staleSummaryQuery(array $runIds = [], ?string $instanceId = null): Builder
    {
        $summaryModel = self::summaryModel();
        $runModel = self::runModel();
        $instanceModel = self::instanceModel();
        $summaryTable = self::table($summaryModel);
        $runTable = self::table($runModel);
        $instanceTable = self::table($instanceModel);

        $query = $summaryModel::query()
            ->join($runTable, sprintf('%s.id', $runTable), '=', sprintf('%s.id', $summaryTable))
            ->leftJoin(
                $instanceTable,
                sprintf('%s.id', $instanceTable),
                '=',
                sprintf('%s.workflow_instance_id', $runTable),
            );

        if ($runIds !== []) {
            $query->whereIn(sprintf('%s.id', $summaryTable), $runIds);
        }

        if ($instanceId !== null) {
            $query->where(sprintf('%s.workflow_instance_id', $summaryTable), $instanceId);
        }

        return $query->where(static function ($drift) use ($summaryTable, $runTable, $instanceTable): void {
            self::orWhereColumnMismatch(
                $drift,
                sprintf('%s.workflow_instance_id', $summaryTable),
                sprintf('%s.workflow_instance_id', $runTable),
            );
            self::orWhereColumnMismatch(
                $drift,
                sprintf('%s.run_number', $summaryTable),
                sprintf('%s.run_number', $runTable),
            );
            self::orWhereColumnMismatch(
                $drift,
                sprintf('%s.class', $summaryTable),
                sprintf('%s.workflow_class', $runTable),
            );
            self::orWhereColumnMismatch(
                $drift,
                sprintf('%s.workflow_type', $summaryTable),
                sprintf('%s.workflow_type', $runTable),
            );
            self::orWhereColumnMismatch(
                $drift,
                sprintf('%s.status', $summaryTable),
                sprintf('%s.status', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.closed_reason', $summaryTable),
                sprintf('%s.closed_reason', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.compatibility', $summaryTable),
                sprintf('%s.compatibility', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.connection', $summaryTable),
                sprintf('%s.connection', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.queue', $summaryTable),
                sprintf('%s.queue', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.started_at', $summaryTable),
                sprintf('%s.started_at', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.closed_at', $summaryTable),
                sprintf('%s.closed_at', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.archived_at', $summaryTable),
                sprintf('%s.archived_at', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.archive_command_id', $summaryTable),
                sprintf('%s.archive_command_id', $runTable),
            );
            self::orWhereNullableColumnMismatch(
                $drift,
                sprintf('%s.archive_reason', $summaryTable),
                sprintf('%s.archive_reason', $runTable),
            );

            $drift->orWhere(static function ($current) use ($summaryTable, $runTable, $instanceTable): void {
                $current->where(sprintf('%s.is_current_run', $summaryTable), true)
                    ->where(static function ($pointer) use ($runTable, $instanceTable): void {
                        $pointer->whereNull(sprintf('%s.current_run_id', $instanceTable))
                            ->orWhereColumn(
                                sprintf('%s.current_run_id', $instanceTable),
                                '!=',
                                sprintf('%s.id', $runTable),
                            );
                    });
            });
            $drift->orWhere(static function ($current) use ($summaryTable, $runTable, $instanceTable): void {
                $current->where(sprintf('%s.is_current_run', $summaryTable), false)
                    ->whereColumn(sprintf('%s.current_run_id', $instanceTable), sprintf('%s.id', $runTable));
            });
        });
    }

    private static function orWhereColumnMismatch($query, string $left, string $right): void
    {
        $query->orWhereColumn($left, '!=', $right);
    }

    private static function orWhereNullableColumnMismatch($query, string $left, string $right): void
    {
        $query->orWhere(static function ($mismatch) use ($left, $right): void {
            $mismatch->whereColumn($left, '!=', $right)
                ->orWhere(static function ($leftMissing) use ($left, $right): void {
                    $leftMissing->whereNull($left)
                        ->whereNotNull($right);
                })
                ->orWhere(static function ($rightMissing) use ($left, $right): void {
                    $rightMissing->whereNotNull($left)
                        ->whereNull($right);
                });
        });
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private static function table(string $model): string
    {
        return (new $model())->getTable();
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

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private static function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
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
}
