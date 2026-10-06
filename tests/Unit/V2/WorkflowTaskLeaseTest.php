<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CancellationCleanupLease;
use Workflow\V2\Support\WorkflowTaskLease;

final class WorkflowTaskLeaseTest extends TestCase
{
    public function testRuntimeConfigurationControlsExpiry(): void
    {
        $now = Carbon::parse('2026-07-14 22:00:00 UTC');
        Carbon::setTestNow($now);
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $this->assertSame(WorkflowTaskLease::DEFAULT_SECONDS, WorkflowTaskLease::seconds());

        config()
            ->set(WorkflowTaskLease::CONFIG_KEY, 8);

        $this->assertSame(8, WorkflowTaskLease::seconds());
        $this->assertTrue(WorkflowTaskLease::expiresAt()->equalTo($now->copy()->addSeconds(8)));
    }

    public function testInvalidRuntimeConfigurationFallsBackToEmbeddedDefault(): void
    {
        config()->set(WorkflowTaskLease::CONFIG_KEY, 0);
        $this->assertSame(WorkflowTaskLease::DEFAULT_SECONDS, WorkflowTaskLease::seconds());

        config()
            ->set(WorkflowTaskLease::CONFIG_KEY, 'invalid');
        $this->assertSame(WorkflowTaskLease::DEFAULT_SECONDS, WorkflowTaskLease::seconds());
    }

    public function testAcceptedCancellationBoundsOwnershipBeforeRootDelivery(): void
    {
        Carbon::setTestNow('2026-10-05T00:00:24Z');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });
        config()
            ->set(WorkflowTaskLease::CONFIG_KEY, 60);
        $run = new WorkflowRun([
            'cancellation_request_command_id' => 'original-request',
            'cancellation_deadline_at' => '2026-10-05T00:00:30Z',
            'cancellation_delivered_at' => null,
        ]);
        $this->assertSame('2026-10-05T00:00:30.000000Z', CancellationCleanupLease::expiresAt($run)->toISOString());
        $this->assertSame('2026-10-05T00:00:30.000000Z', CancellationCleanupLease::forScopes($run)->toISOString());
        Carbon::setTestNow('2026-10-05T00:00:00Z');
        $this->assertSame('2026-10-05T00:00:10.000000Z', CancellationCleanupLease::expiresAt($run)->toISOString());
        $this->assertSame('2026-10-05T00:00:30.000000Z', CancellationCleanupLease::forScopes($run)->toISOString());
    }
}
