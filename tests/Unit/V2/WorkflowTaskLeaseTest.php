<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
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
}
