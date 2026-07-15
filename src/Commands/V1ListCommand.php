<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;
use Workflow\Models\StoredWorkflow;

class V1ListCommand extends Command
{
    protected $signature = 'workflow:v1:list
        {--status= : Filter by status (running, pending, etc.)}
        {--json : Output as JSON}';

    protected $description = 'List active v1 workflows from the workflows table after upgrading to v2';

    public function handle(): int
    {
        $storedWorkflow = new StoredWorkflow();

        $query = $storedWorkflow->getConnection()
            ->table($storedWorkflow->getTable())
            ->whereNotIn('status', ['completed', 'failed', 'cancelled']);

        $status = $this->option('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $workflows = $query->orderBy('created_at', 'desc')
            ->get(['id', 'class', 'status', 'created_at', 'updated_at']);

        if ((bool) $this->option('json')) {
            $this->line($workflows->toJson(JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($workflows->isEmpty()) {
            $this->info('No active v1 workflows found in the workflows table.');
            $this->line('');
            $this->line('All v1 workflows have completed. You may safely drop v1 tables if desired:');
            $this->line('');
            $this->line('  DROP TABLE IF EXISTS workflow_relationships;');
            $this->line('  DROP TABLE IF EXISTS workflow_exceptions;');
            $this->line('  DROP TABLE IF EXISTS workflow_timers;');
            $this->line('  DROP TABLE IF EXISTS workflow_signals;');
            $this->line('  DROP TABLE IF EXISTS workflow_logs;');
            $this->line('  DROP TABLE IF EXISTS workflows;');
            $this->line('');

            return self::SUCCESS;
        }

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Class', 'Status', 'Created']);

        foreach ($workflows as $workflow) {
            $table->addRow([
                $this->shortenId((string) $workflow->id),
                $this->shortenClass($workflow->class),
                $workflow->status,
                $this->formatDate($workflow->created_at),
            ]);
        }

        $table->render();

        $this->line('');
        $this->info(sprintf('Found %d active v1 workflow(s) in the workflows table.', $workflows->count()));
        $this->line('');
        $this->line('These workflows will continue executing on the v1 engine until they complete.');
        $this->line('Run this command periodically to track v1 workflow completion.');
        $this->line('');

        return self::SUCCESS;
    }

    private function shortenId(string $id): string
    {
        if (strlen($id) <= 24) {
            return $id;
        }

        return substr($id, 0, 24) . '...';
    }

    private function shortenClass(?string $class): string
    {
        if ($class === null) {
            return '<none>';
        }

        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    private function formatDate(?string $date): string
    {
        if ($date === null) {
            return 'unknown';
        }

        try {
            $carbon = \Carbon\Carbon::parse($date);

            return $carbon->diffForHumans();
        } catch (\Exception $e) {
            return $date;
        }
    }
}
