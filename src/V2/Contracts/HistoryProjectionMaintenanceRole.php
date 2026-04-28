<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

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
}
