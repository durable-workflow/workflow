<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ParallelGroupMetadataBackfill;
use Workflow\V2\Support\RunSummaryProjector;

#[AsCommand(name: 'workflow:v2:backfill-parallel-group-metadata')]
class V2BackfillParallelGroupMetadataCommand extends Command
{
    protected $signature = 'workflow:v2:backfill-parallel-group-metadata
        {--run-id=* : Backfill one or more selected workflow run ids}
        {--instance-id= : Backfill every run for one workflow instance id}
        {--dry-run : Report affected history events without changing history or projections}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill missing Workflow v2 parallel all-group metadata from preview side tables onto typed history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $runQuery = $this->runQuery($this->runIds(), $this->stringOption('instance-id'));
        $matchedRuns = (clone $runQuery)->count();

        $report = [
            'dry_run' => $dryRun,
            'runs_matched' => $matchedRuns,
            'runs_changed' => 0,
            'projections_rebuilt' => 0,
            'activity_events_scanned' => 0,
            'activity_events_updated' => 0,
            'activity_events_would_update' => 0,
            'activity_events_already_authoritative' => 0,
            'activity_events_without_sidecar_metadata' => 0,
            'child_events_scanned' => 0,
            'child_events_updated' => 0,
            'child_events_would_update' => 0,
            'child_events_already_authoritative' => 0,
            'child_events_without_sidecar_metadata' => 0,
            'failures' => [],
        ];

        $runQuery->chunkById(100, function ($runs) use (&$report, $dryRun): void {
            foreach ($runs as $run) {
                if (! $run instanceof WorkflowRun) {
                    continue;
                }

                try {
                    $runReport = ParallelGroupMetadataBackfill::backfillRun($run, $dryRun);
                    $this->mergeRunReport($report, $runReport);

                    $changed = ($runReport['activity_events_updated'] + $runReport['child_events_updated']) > 0;

                    if (! $dryRun && $changed) {
                        $report['runs_changed']++;
                        RunSummaryProjector::project($run->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                            'childLinks.childRun.historyEvents',
                        ]));
                        $report['projections_rebuilt']++;
                    }
                } catch (Throwable $exception) {
                    $report['failures'][] = [
                        'run_id' => $run->id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        });

        $this->renderReport($report);

        return $report['failures'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param list<string> $runIds
     */
    private function runQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();

        $query = $runModel::query()
            ->with([
                'activityExecutions',
                'childLinks',
                'historyEvents',
            ]);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query;
    }

    /**
     * @return class-string<WorkflowRun>
     */
    private function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('run-id'),
        ))));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, int> $runReport
     */
    private function mergeRunReport(array &$report, array $runReport): void
    {
        foreach ($runReport as $key => $value) {
            if (! array_key_exists($key, $report)) {
                continue;
            }

            $report[$key] += $value;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderReport(array $report): void
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());
            }

            return;
        }

        if ($report['dry_run']) {
            $this->info(sprintf(
                'Would backfill %d activity and %d child parallel-group history event(s).',
                $report['activity_events_would_update'],
                $report['child_events_would_update'],
            ));
        } else {
            $this->info(sprintf(
                'Backfilled %d activity and %d child parallel-group history event(s).',
                $report['activity_events_updated'],
                $report['child_events_updated'],
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf(
                'Failed to backfill parallel-group metadata for run [%s]: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }
    }
}
