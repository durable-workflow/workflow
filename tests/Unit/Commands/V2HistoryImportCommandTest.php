<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;

final class V2HistoryImportCommandTest extends TestCase
{
    public function testItRejectsMissingMalformedAndNonObjectBundles(): void
    {
        $missing = sys_get_temp_dir() . '/workflow-v2-import-missing-' . Str::ulid() . '.json';

        $this->assertSame(1, Artisan::call('workflow:v2:history-import', [
            'bundle' => $missing,
        ]));
        $this->assertStringContainsString('was not found', Artisan::output());

        $path = $this->bundleFile('{');
        $this->assertSame(1, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
        ]));

        file_put_contents($path, '"not an object"');
        $this->assertSame(1, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
        ]));
        $this->assertStringContainsString('must decode to an object', Artisan::output());
    }

    public function testItReportsRejectedBundlesWithoutWritingRows(): void
    {
        $path = $this->bundleFile('{}');

        $this->assertSame(1, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
            '--require-signature' => true,
            '--json' => true,
        ]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('rejected', $report['status']);
        $this->assertContains('bundle.signature_required', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertSame(0, WorkflowRun::query()->count());

        $this->assertSame(1, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
            '--require-signature' => true,
        ]));
        $this->assertStringContainsString('bundle.signature_required', Artisan::output());
    }

    public function testItDryRunsImportsAndDeduplicatesARealExport(): void
    {
        [$runId, $bundle] = $this->completedExport();
        $path = $this->bundleFile(json_encode($bundle, JSON_THROW_ON_ERROR));

        $this->assertSame(0, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
            '--dry-run' => true,
            '--namespace' => ' target-namespace ',
            '--import-id' => ' audit-123 ',
            '--json' => true,
        ]));
        $dryRun = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('dry_run', $dryRun['status']);
        $this->assertSame('target-namespace', $dryRun['workflow']['namespace']);
        $this->assertSame(1, $dryRun['rows']['workflow_runs']);
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());

        $this->assertSame(0, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
            '--namespace' => 'target-namespace',
            '--import-id' => 'audit-123',
        ]));
        $this->assertStringContainsString('imported', Artisan::output());
        $this->assertSame('target-namespace', WorkflowRun::query()->findOrFail($runId)->namespace);
        $this->assertSame('audit-123', WorkflowRun::query()->findOrFail($runId)->import_id);

        $this->assertSame(0, Artisan::call('workflow:v2:history-import', [
            'bundle' => $path,
            '--json' => true,
        ]));
        $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('already_imported', $again['status']);
        $this->assertSame(1, WorkflowRun::query()->whereKey($runId)->count());
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function completedExport(): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'history-import-command-' . Str::lower((string) Str::ulid()),
            'workflow_class' => 'App\\Workflows\\ImportWorkflow',
            'workflow_type' => 'history.import',
            'namespace' => 'embedded-source',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ImportWorkflow',
            'workflow_type' => 'history.import',
            'namespace' => 'embedded-source',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'output_payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_type' => 'history.import',
        ]);
        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::WorkflowCompleted, [
            'output' => [
                'ok' => true,
            ],
        ]);
        $bundle = HistoryExport::forRun($run->refresh());

        DB::table('workflow_history_events')->where('workflow_run_id', $run->id)->delete();
        $run->delete();
        $instance->delete();

        return [$run->id, $bundle];
    }

    private function bundleFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/workflow-v2-history-import-' . Str::ulid() . '.json';
        file_put_contents($path, $contents);
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }
}
