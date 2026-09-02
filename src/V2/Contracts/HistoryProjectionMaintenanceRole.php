<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Illuminate\Database\Eloquent\Model;

interface HistoryProjectionMaintenanceRole extends HistoryProjectionRole
{
    /**
     * @param list<string> $runIds
     * @return array{
     *     run_summaries: int,
     *     run_waits: int,
     *     run_timeline_entries: int,
     *     run_timer_entries: int,
     *     run_lineage_entries: int
     * }
     */
    public function pruneStaleProjections(array $runIds = [], ?string $instanceId = null, bool $dryRun = false): array;

    /**
     * @param class-string<Model> $projectionModel
     * @param list<string> $seenProjectionIds
     */
    public function pruneStaleProjectionRowsForRun(
        string $projectionModel,
        string $runId,
        array $seenProjectionIds,
    ): void;
}
