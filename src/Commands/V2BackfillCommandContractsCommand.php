<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\RunCommandContract;

#[AsCommand(name: 'workflow:v2:backfill-command-contracts')]
final class V2BackfillCommandContractsCommand extends Command
{
    protected $signature = 'workflow:v2:backfill-command-contracts
        {--run-id=* : Limit the backfill to one or more workflow run ids}
        {--dry-run : Report affected runs without writing WorkflowStarted history}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill WorkflowStarted command-contract snapshots for legacy Workflow v2 runs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = [
            'dry_run' => $dryRun,
            'run_ids' => $this->runIds(),
            'scanned_runs' => 0,
            'backfill_needed_runs' => 0,
            'backfilled_runs' => 0,
            'backfill_unavailable_runs' => 0,
            'failures' => [],
        ];

        $this->queryRuns()
            ->with([
                'historyEvents' => static function ($query): void {
                    $query->where('event_type', HistoryEventType::WorkflowStarted->value)
                        ->orderBy('sequence');
                },
            ])
            ->chunkById(200, static function ($runs) use (&$report, $dryRun): void {
                foreach ($runs as $run) {
                    if (! $run instanceof WorkflowRun) {
                        continue;
                    }

                    $report['scanned_runs']++;
                    $status = RunCommandContract::backfillStatus($run);

                    if (! $status['needed']) {
                        continue;
                    }

                    $report['backfill_needed_runs']++;

                    if (! $status['available']) {
                        $report['backfill_unavailable_runs']++;

                        continue;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    try {
                        $result = RunCommandContract::backfillRun($run);

                        if ($result['backfilled']) {
                            $report['backfilled_runs']++;
                        }
                    } catch (Throwable $exception) {
                        $report['failures'][] = [
                            'run_id' => $run->id,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            });

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->renderHumanReport($report);
        }

        return $report['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function queryRuns()
    {
        $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $runIds = $this->runIds();

        return $runModel::query()
            ->whereHas('historyEvents', static function ($query): void {
                $query->where('event_type', HistoryEventType::WorkflowStarted->value);
            })
            ->when($runIds !== [], static function ($query) use ($runIds): void {
                $query->whereIn('id', $runIds);
            });
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     scanned_runs: int,
     *     backfill_needed_runs: int,
     *     backfilled_runs: int,
     *     backfill_unavailable_runs: int,
     *     failures: list<array{run_id: string, message: string}>
     * } $report
     */
    private function renderHumanReport(array $report): void
    {
        $this->line('Workflow v2 command-contract backfill completed.');
        $this->line(sprintf('Scanned %d run(s).', $report['scanned_runs']));
        $this->line(sprintf('Found %d run(s) needing backfill.', $report['backfill_needed_runs']));

        if ($report['dry_run']) {
            $this->line(sprintf(
                'Would backfill %d run(s); %d run(s) have no available current workflow definition.',
                max(0, $report['backfill_needed_runs'] - $report['backfill_unavailable_runs']),
                $report['backfill_unavailable_runs'],
            ));
        } else {
            $this->line(sprintf('Backfilled %d run(s).', $report['backfilled_runs']));
            $this->line(sprintf(
                'Skipped %d run(s) with no available current workflow definition.',
                $report['backfill_unavailable_runs'],
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf(
                'Run [%s] failed command-contract backfill: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }
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
}
