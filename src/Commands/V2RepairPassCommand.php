<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\TaskWatchdog;

#[AsCommand(name: 'workflow:v2:repair-pass')]
class V2RepairPassCommand extends Command
{
    protected $signature = 'workflow:v2:repair-pass
        {--run-id=* : Limit the sweep to one or more workflow run ids}
        {--instance-id= : Limit the sweep to one workflow instance id}
        {--connection= : Record the repair pass heartbeat against a queue connection scope}
        {--queue= : Record the repair pass heartbeat against a queue scope}
        {--respect-throttle : Respect the queue-loop repair throttle instead of forcing a repair pass}
        {--json : Output the repair pass report as JSON}';

    protected $description = 'Run one Workflow v2 repair sweep using the current repair candidate scan and backoff policy, optionally limited to selected runs';

    public function handle(): int
    {
        $report = TaskWatchdog::runPass(
            $this->stringOption('connection'),
            $this->stringOption('queue'),
            respectThrottle: (bool) $this->option('respect-throttle'),
            runIds: $this->runIds(),
            instanceId: $this->stringOption('instance-id'),
        );

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

        return $report['existing_task_failures'] === []
            && $report['missing_run_failures'] === []
            && $report['command_contract_failures'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param array{
     *     connection: string|null,
     *     queue: string|null,
     *     run_ids: list<string>,
     *     instance_id: string|null,
     *     respect_throttle: bool,
     *     throttled: bool,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     repaired_existing_tasks: int,
     *     repaired_missing_tasks: int,
     *     dispatched_tasks: int,
     *     selected_command_contract_candidates: int,
     *     backfilled_command_contracts: int,
     *     command_contract_backfill_unavailable: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     command_contract_failures: list<array{run_id: string, message: string}>
     * } $report
     */
    private function renderHumanReport(array $report): void
    {
        if ($report['throttled']) {
            $this->warn('Skipped repair pass because the watchdog loop throttle is already held.');

            return;
        }

        $this->line('Workflow v2 repair pass completed.');
        $this->line(sprintf(
            'Selected %d existing task candidate(s) and %d missing-task run candidate(s).',
            $report['selected_existing_task_candidates'],
            $report['selected_missing_task_candidates'],
        ));
        $this->line(sprintf(
            'Repaired %d existing task(s), %d missing task(s), and dispatched %d task(s).',
            $report['repaired_existing_tasks'],
            $report['repaired_missing_tasks'],
            $report['dispatched_tasks'],
        ));
        $this->line(sprintf(
            'Selected %d command-contract candidate(s), backfilled %d, and left %d unavailable on this build.',
            $report['selected_command_contract_candidates'],
            $report['backfilled_command_contracts'],
            $report['command_contract_backfill_unavailable'],
        ));

        foreach ($report['existing_task_failures'] as $failure) {
            $this->error(sprintf(
                'Existing task candidate [%s] failed repair: %s',
                $failure['candidate_id'],
                $failure['message'],
            ));
        }

        foreach ($report['missing_run_failures'] as $failure) {
            $this->error(sprintf(
                'Missing-task run [%s] failed repair: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }

        foreach ($report['command_contract_failures'] as $failure) {
            $this->error(sprintf(
                'Command-contract candidate [%s] failed backfill: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }
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
