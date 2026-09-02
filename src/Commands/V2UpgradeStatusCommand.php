<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Workflow\Models\StoredWorkflow;
use Workflow\V2\Support\LaravelEmbeddedUpgradeContract;

final class V2UpgradeStatusCommand extends Command
{
    protected $signature = 'workflow:v2:upgrade-status
        {--strategy= : Required transition strategy: drain or coexist}
        {--json : Output a machine-readable status report}';

    protected $description = 'Verify v1 drain or isolated v1/v2 coexistence before routing embedded v2 starts';

    public function handle(): int
    {
        $strategy = $this->option('strategy');

        if (! is_string($strategy) || ! in_array($strategy, ['drain', 'coexist'], true)) {
            $this->error('Choose an explicit upgrade strategy with --strategy=drain or --strategy=coexist.');

            return self::FAILURE;
        }

        $report = $this->report($strategy);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderReport($report);
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $strategy): array
    {
        $v1SchemaPresent = $this->hasTable('workflows');
        $v2Tables = ['workflow_instances', 'workflow_runs', 'workflow_history_events', 'workflow_tasks'];
        $missingV2Tables = array_values(array_filter(
            $v2Tables,
            fn (string $table): bool => ! $this->hasTable($table),
        ));

        $openRuns = $v1SchemaPresent ? $this->openV1Runs() : collect();
        $v1Queues = $openRuns
            ->map(fn (Model $workflow): string => $this->v1Queue($workflow))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $v2Queue = $this->configuredV2Queue();

        $findings = [];

        if ($missingV2Tables !== []) {
            $findings[] = 'v2_schema_incomplete';
        }

        if ($strategy === 'drain' && $openRuns->isNotEmpty()) {
            $findings[] = 'v1_open_runs_remain';
        }

        if ($strategy === 'coexist' && $v2Queue === null) {
            $findings[] = 'v2_queue_not_configured';
        }

        if ($strategy === 'coexist' && $v2Queue !== null && in_array($v2Queue, $v1Queues, true)) {
            $findings[] = 'v2_queue_not_isolated';
        }

        return [
            'schema' => 'durable-workflow.laravel-embedded-upgrade.status',
            'version' => 1,
            'contract' => [
                'schema' => LaravelEmbeddedUpgradeContract::SCHEMA,
                'version' => LaravelEmbeddedUpgradeContract::VERSION,
            ],
            'strategy' => $strategy,
            'ready' => $findings === [],
            'findings' => $findings,
            'v1' => [
                'schema_present' => $v1SchemaPresent,
                'open_run_count' => $openRuns->count(),
                'status_counts' => $openRuns->countBy(
                    static fn (Model $workflow): string => (string) $workflow->getRawOriginal('status'),
                )->sortKeys()
                    ->all(),
                'queues' => $v1Queues,
                'history_owner' => 'v1',
            ],
            'embedded_v2' => [
                'schema_present' => $missingV2Tables === [],
                'missing_tables' => $missingV2Tables,
                'connection' => $this->configuredV2Connection(),
                'queue' => $v2Queue,
                'history_owner' => 'embedded_v2',
            ],
            'state_transfer' => 'none',
            'composer_change_migrates_history' => false,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    private function openV1Runs(): \Illuminate\Database\Eloquent\Collection
    {
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

        /** @var Model $model */
        $model = new $modelClass();

        return $model->newQuery()
            ->whereNotIn('status', ['completed', 'failed', 'cancelled', 'continued'])
            ->orderBy('created_at')
            ->get();
    }

    private function v1Queue(Model $workflow): string
    {
        if (method_exists($workflow, 'effectiveQueue')) {
            $queue = $workflow->effectiveQueue();

            if (is_string($queue) && $queue !== '') {
                return $queue;
            }
        }

        $connection = config('queue.default');
        $queue = is_string($connection) ? config('queue.connections.' . $connection . '.queue') : null;

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    private function configuredV2Connection(): ?string
    {
        $connection = config('workflows.v2.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function configuredV2Queue(): ?string
    {
        $queue = config('workflows.v2.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function hasTable(string $table): bool
    {
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

        /** @var Model $model */
        $model = new $modelClass();
        $connection = $model->getConnectionName();

        return Schema::connection($connection)->hasTable($table);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderReport(array $report): void
    {
        $this->line(sprintf('Strategy: %s', $report['strategy']));
        $this->line(sprintf('Open v1 runs: %d', $report['v1']['open_run_count']));
        $this->line(sprintf('V1 queues: %s', implode(', ', $report['v1']['queues']) ?: '<none>'));
        $this->line(sprintf('Embedded v2 queue: %s', $report['embedded_v2']['queue'] ?? '<not configured>'));

        if ($report['ready']) {
            $this->info('The selected embedded v2 transition strategy is ready.');

            return;
        }

        $this->error('The selected embedded v2 transition strategy is not ready.');

        foreach ($report['findings'] as $finding) {
            $this->line(' - ' . $finding);
        }
    }
}
