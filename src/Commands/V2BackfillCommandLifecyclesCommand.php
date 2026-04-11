<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Support\CommandLifecycleBackfill;

#[AsCommand(name: 'workflow:v2:backfill-command-lifecycles')]
class V2BackfillCommandLifecyclesCommand extends Command
{
    protected $signature = 'workflow:v2:backfill-command-lifecycles
        {--run-id=* : Backfill one or more selected workflow run ids}
        {--instance-id= : Backfill every signal/update command for one workflow instance id}
        {--dry-run : Report the affected rows without changing command lifecycle state}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill Workflow v2 signal/update lifecycle rows and stamp their ids onto typed history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $commandQuery = $this->commandQuery($this->runIds(), $this->stringOption('instance-id'));
        $matchedCommands = (clone $commandQuery)->count();

        $report = [
            'dry_run' => $dryRun,
            'commands_matched' => $matchedCommands,
            'signal_lifecycles_backfilled' => 0,
            'signal_lifecycles_would_backfill' => 0,
            'update_lifecycles_backfilled' => 0,
            'update_lifecycles_would_backfill' => 0,
            'history_events_backfilled' => 0,
            'history_events_would_backfill' => 0,
            'failures' => [],
        ];

        $commandQuery->chunkById(100, function ($commands) use (&$report, $dryRun): void {
            foreach ($commands as $command) {
                try {
                    $plan = CommandLifecycleBackfill::plan($command);

                    if ($plan === null) {
                        continue;
                    }

                    if (! $plan['row_missing'] && $plan['history_events_missing'] === 0) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->recordPlannedBackfill($report, $plan);

                        continue;
                    }

                    $applied = DB::transaction(static fn () => CommandLifecycleBackfill::backfill($command));

                    if ($applied !== null) {
                        $this->recordAppliedBackfill($report, $applied);
                    }
                } catch (Throwable $exception) {
                    $report['failures'][] = [
                        'command_id' => $command->id,
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
    private function commandQuery(array $runIds, ?string $instanceId)
    {
        $commandModel = $this->commandModel();

        $query = $commandModel::query()
            ->with(['historyEvents', 'signalRecord', 'updateRecord'])
            ->whereIn('command_type', [CommandType::Signal->value, CommandType::Update->value]);

        if ($runIds !== []) {
            $query->whereIn('workflow_run_id', $runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query->orderBy('id');
    }

    /**
     * @return class-string<WorkflowCommand>
     */
    private function commandModel(): string
    {
        /** @var class-string<WorkflowCommand> $model */
        $model = config('workflows.v2.command_model', WorkflowCommand::class);

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
     *     commands_matched: int,
     *     signal_lifecycles_backfilled: int,
     *     signal_lifecycles_would_backfill: int,
     *     update_lifecycles_backfilled: int,
     *     update_lifecycles_would_backfill: int,
     *     history_events_backfilled: int,
     *     history_events_would_backfill: int,
     *     failures: list<array{command_id: string, message: string}>
     * } $report
     * @param array{type: 'signal'|'update', row_missing: bool, history_events_missing: int} $plan
     */
    private function recordPlannedBackfill(array &$report, array $plan): void
    {
        if ($plan['row_missing']) {
            if ($plan['type'] === 'signal') {
                $report['signal_lifecycles_would_backfill']++;
            } else {
                $report['update_lifecycles_would_backfill']++;
            }
        }

        $report['history_events_would_backfill'] += $plan['history_events_missing'];
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     commands_matched: int,
     *     signal_lifecycles_backfilled: int,
     *     signal_lifecycles_would_backfill: int,
     *     update_lifecycles_backfilled: int,
     *     update_lifecycles_would_backfill: int,
     *     history_events_backfilled: int,
     *     history_events_would_backfill: int,
     *     failures: list<array{command_id: string, message: string}>
     * } $report
     * @param array{type: 'signal'|'update', row_missing: bool, history_events_missing: int} $applied
     */
    private function recordAppliedBackfill(array &$report, array $applied): void
    {
        if ($applied['row_missing']) {
            if ($applied['type'] === 'signal') {
                $report['signal_lifecycles_backfilled']++;
            } else {
                $report['update_lifecycles_backfilled']++;
            }
        }

        $report['history_events_backfilled'] += $applied['history_events_missing'];
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     commands_matched: int,
     *     signal_lifecycles_backfilled: int,
     *     signal_lifecycles_would_backfill: int,
     *     update_lifecycles_backfilled: int,
     *     update_lifecycles_would_backfill: int,
     *     history_events_backfilled: int,
     *     history_events_would_backfill: int,
     *     failures: list<array{command_id: string, message: string}>
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
                'Would backfill %d signal lifecycle(s), %d update lifecycle(s), and stamp %d history event(s).',
                $report['signal_lifecycles_would_backfill'],
                $report['update_lifecycles_would_backfill'],
                $report['history_events_would_backfill'],
            ));
        } else {
            $this->info(sprintf(
                'Backfilled %d signal lifecycle(s), %d update lifecycle(s), and stamped %d history event(s).',
                $report['signal_lifecycles_backfilled'],
                $report['update_lifecycles_backfilled'],
                $report['history_events_backfilled'],
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf(
                'Failed to backfill command lifecycle for command [%s]: %s',
                $failure['command_id'],
                $failure['message'],
            ));
        }
    }
}
