<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunSummaryProjector;

#[AsCommand(name: 'workflow:v2:rebuild-projections')]
class V2RebuildProjectionsCommand extends Command
{
    protected $signature = 'workflow:v2:rebuild-projections
        {--run-id=* : Rebuild one or more selected workflow run ids}
        {--instance-id= : Rebuild every run for one workflow instance id}
        {--missing : Only rebuild runs that do not have a run-summary row}
        {--prune-stale : Delete run-summary rows whose workflow run row no longer exists}
        {--dry-run : Report the affected rows without changing projection tables}
        {--json : Output the rebuild report as JSON}';

    protected $description = 'Rebuild Workflow v2 projection rows that are safe to derive from durable runtime state';

    public function handle(): int
    {
        $runIds = $this->runIds();
        $instanceId = $this->stringOption('instance-id');
        $missingOnly = (bool) $this->option('missing');
        $pruneStale = (bool) $this->option('prune-stale');
        $dryRun = (bool) $this->option('dry-run');

        $runQuery = $this->runQuery($runIds, $instanceId, $missingOnly);
        $matchedRuns = (clone $runQuery)->count();

        $report = [
            'dry_run' => $dryRun,
            'runs_matched' => $matchedRuns,
            'run_summaries_rebuilt' => 0,
            'run_summaries_would_rebuild' => $dryRun ? $matchedRuns : 0,
            'run_summaries_pruned' => 0,
            'run_summaries_would_prune' => 0,
            'failures' => [],
        ];

        if (! $dryRun) {
            $runQuery->chunkById(100, static function ($runs) use (&$report): void {
                foreach ($runs as $run) {
                    try {
                        RunSummaryProjector::project($run);
                        $report['run_summaries_rebuilt']++;
                    } catch (Throwable $exception) {
                        $report['failures'][] = [
                            'run_id' => $run->id,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            });
        }

        if ($pruneStale) {
            $staleQuery = $this->staleSummaryQuery($runIds, $instanceId);
            $staleCount = (clone $staleQuery)->count();

            if ($dryRun) {
                $report['run_summaries_would_prune'] = $staleCount;
            } else {
                $report['run_summaries_pruned'] = $staleQuery->delete();
            }
        }

        $this->renderReport($report);

        return $report['failures'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param list<string> $runIds
     */
    private function runQuery(array $runIds, ?string $instanceId, bool $missingOnly)
    {
        $runModel = $this->runModel();
        $summaryModel = $this->summaryModel();

        $query = $runModel::query()
            ->with([
                'instance.runs',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
            ]);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        if ($missingOnly) {
            $query->whereNotIn('id', $summaryModel::query()->select('id'));
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleSummaryQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $summaryModel = $this->summaryModel();

        $query = $summaryModel::query()
            ->whereNotIn('id', $runModel::query()->select('id'));

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
     * @return class-string<WorkflowRunSummary>
     */
    private function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

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
     * @param array{
     *     dry_run: bool,
     *     runs_matched: int,
     *     run_summaries_rebuilt: int,
     *     run_summaries_would_rebuild: int,
     *     run_summaries_pruned: int,
     *     run_summaries_would_prune: int,
     *     failures: list<array{run_id: string, message: string}>
     * } $report
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
                'Would rebuild %d run-summary projection row(s).',
                $report['run_summaries_would_rebuild'],
            ));

            if ($report['run_summaries_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale run-summary projection row(s).',
                    $report['run_summaries_would_prune'],
                ));
            }
        } else {
            $this->info(sprintf('Rebuilt %d run-summary projection row(s).', $report['run_summaries_rebuilt']));

            if ($report['run_summaries_pruned'] > 0) {
                $this->info(sprintf(
                    'Pruned %d stale run-summary projection row(s).',
                    $report['run_summaries_pruned'],
                ));
            }
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf('Failed to rebuild run [%s]: %s', $failure['run_id'], $failure['message']));
        }
    }
}
