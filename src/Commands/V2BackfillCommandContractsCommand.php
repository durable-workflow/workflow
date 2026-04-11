<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunCommandContract;

#[AsCommand(name: 'workflow:v2:backfill-command-contracts')]
class V2BackfillCommandContractsCommand extends Command
{
    protected $signature = 'workflow:v2:backfill-command-contracts
        {--run-id=* : Backfill one or more selected workflow run ids}
        {--instance-id= : Backfill every run for one workflow instance id}
        {--dry-run : Report the affected rows without changing history}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill missing Workflow v2 query, signal, and update contract snapshots onto WorkflowStarted history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $runQuery = $this->runQuery($this->runIds(), $this->stringOption('instance-id'));
        $matchedRuns = (clone $runQuery)->count();

        $report = [
            'dry_run' => $dryRun,
            'runs_matched' => $matchedRuns,
            'command_contracts_needing_backfill' => 0,
            'command_contracts_backfilled' => 0,
            'command_contracts_would_backfill' => 0,
            'command_contracts_backfill_unavailable' => 0,
            'failures' => [],
        ];

        $runQuery->chunkById(100, static function ($runs) use (&$report, $dryRun): void {
            foreach ($runs as $run) {
                try {
                    $state = RunCommandContract::historyBackfillState($run);

                    if (! $state['needed']) {
                        continue;
                    }

                    $report['command_contracts_needing_backfill']++;

                    if (! $state['available']) {
                        $report['command_contracts_backfill_unavailable']++;

                        continue;
                    }

                    if ($dryRun) {
                        $report['command_contracts_would_backfill']++;

                        continue;
                    }

                    if (RunCommandContract::backfillHistory($run)) {
                        $report['command_contracts_backfilled']++;
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

        $query = $runModel::query()->with('historyEvents');

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
     * @param array{
     *     dry_run: bool,
     *     runs_matched: int,
     *     command_contracts_needing_backfill: int,
     *     command_contracts_backfilled: int,
     *     command_contracts_would_backfill: int,
     *     command_contracts_backfill_unavailable: int,
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
                'Would backfill %d command-contract history snapshot(s).',
                $report['command_contracts_would_backfill'],
            ));
        } else {
            $this->info(sprintf(
                'Backfilled %d command-contract history snapshot(s).',
                $report['command_contracts_backfilled'],
            ));
        }

        if ($report['command_contracts_backfill_unavailable'] > 0) {
            $this->warn(sprintf(
                '%d run(s) still need command-contract normalization, but the current build cannot resolve their workflow definitions.',
                $report['command_contracts_backfill_unavailable'],
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf(
                'Failed to backfill command contracts for run [%s]: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }
    }
}
