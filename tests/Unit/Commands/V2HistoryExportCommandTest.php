<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;

final class V2HistoryExportCommandTest extends TestCase
{
    public function testItExportsSelectedRunHistoryToAFile(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        [$instance, $run] = $this->createCompletedRun('history-export-command-instance');
        $path = sys_get_temp_dir() . '/workflow-v2-history-export-' . Str::ulid() . '.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $this->artisan('workflow:v2:history-export', [
            'target' => $instance->id,
            '--run-id' => $run->id,
            '--output' => $path,
            '--pretty' => true,
        ])
            ->expectsOutput(sprintf('Exported workflow history for run [%s] to [%s].', $run->id, $path))
            ->assertSuccessful();

        $this->assertFileExists($path);

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(HistoryExport::SCHEMA, $bundle['schema']);
        $this->assertSame(HistoryExport::SCHEMA_VERSION, $bundle['schema_version']);
        $this->assertSame($instance->id, $bundle['workflow']['instance_id']);
        $this->assertSame($run->id, $bundle['workflow']['run_id']);
        $this->assertSame(['WorkflowStarted', 'WorkflowCompleted'], array_column($bundle['history_events'], 'type'));
    }

    public function testItCanExportARunTargetToAFile(): void
    {
        [$instance, $run] = $this->createCompletedRun('history-export-command-run-target');
        $path = sys_get_temp_dir() . '/workflow-v2-history-export-' . Str::ulid() . '.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $this->artisan('workflow:v2:history-export', [
            'target' => $run->id,
            '--run' => true,
            '--output' => $path,
        ])->assertSuccessful();

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($instance->id, $bundle['workflow']['instance_id']);
        $this->assertSame($run->id, $bundle['workflow']['run_id']);
        $this->assertTrue($bundle['history_complete']);
    }

    public function testItRejectsAmbiguousRunOptions(): void
    {
        [$instance, $run] = $this->createCompletedRun('history-export-command-ambiguous');

        $this->artisan('workflow:v2:history-export', [
            'target' => $instance->id,
            '--run' => true,
            '--run-id' => $run->id,
        ])->assertFailed();
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCompletedRun(string $instanceId): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\HistoryExportWorkflow',
            'workflow_type' => 'history.export',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(5),
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\HistoryExportWorkflow',
            'workflow_type' => 'history.export',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            [
                'workflow_type' => 'history.export',
            ],
        );
        WorkflowHistoryEvent::record(
            $run->refresh(),
            HistoryEventType::WorkflowCompleted,
            [
                'result_available' => true,
            ],
        );

        return [$instance, $run->refresh()];
    }
}
