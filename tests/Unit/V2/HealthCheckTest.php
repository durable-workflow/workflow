<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HealthCheck;

final class HealthCheckTest extends TestCase
{
    public function testSnapshotFailsReadinessWhenBackendCapabilitiesAreUnsupported(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');

        $snapshot = HealthCheck::snapshot();

        $this->assertSame('2026-04-09T12:00:00.000000Z', $snapshot['generated_at']);
        $this->assertSame('error', $snapshot['status']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame(503, HealthCheck::httpStatus($snapshot));
        $this->assertSame('backend_capabilities', $snapshot['checks'][0]['name']);
        $this->assertSame('error', $snapshot['checks'][0]['status']);
        $this->assertContains('queue_sync_unsupported', array_column(
            $snapshot['checks'][0]['data']['issues'],
            'code',
        ));
    }

    public function testSnapshotWarnsWhenRunSummaryProjectionNeedsRebuild(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-missing-summary',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => 'health-missing-summary-run',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'run_summary_projection');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertTrue($snapshot['healthy']);
        $this->assertSame(200, HealthCheck::httpStatus($snapshot));
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(1, $projection['data']['needs_rebuild']);
        $this->assertSame(1, $projection['data']['missing']);
        $this->assertSame(0, $projection['data']['orphaned']);
    }
}
