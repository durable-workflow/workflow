<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Tests\TestCase;

final class V2DoctorCommandTest extends TestCase
{
    public function testStrictModeFailsWhenTheQueueDriverIsSync(): void
    {
        config()->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $this->artisan('workflow:v2:doctor', [
            '--strict' => true,
        ])->assertFailed();
    }

    public function testJsonOutputSucceedsWithoutStrictMode(): void
    {
        config()->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $this->artisan('workflow:v2:doctor', [
            '--json' => true,
        ])->assertSuccessful();
    }

    public function testPollModeSyncQueueIsReportedAsInfoNotWarning(): void
    {
        // TD-078: in poll mode the queue-driver diagnostic is informational,
        // not a capability failure. The doctor command must render it with an
        // [INFO] tag rather than Laravel's amber [WARN] tag, and --strict must
        // still succeed.
        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $this->artisan('workflow:v2:doctor', [
            '--strict' => true,
        ])
            ->expectsOutputToContain('[INFO] [queue_sync_unsupported]')
            ->doesntExpectOutputToContain('[WARN] [queue_sync_unsupported]')
            ->assertSuccessful();
    }

    public function testPollModeMissingQueueConnectionIsReportedAsInfoNotWarning(): void
    {
        // Mirror of the sync-driver case for a missing queue connection in
        // poll mode. Same rule: info-severity output, not a warning, and
        // --strict still succeeds.
        config()
            ->set('queue.default', 'missing-driver');
        config()
            ->set('queue.connections.missing-driver', []);
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $this->artisan('workflow:v2:doctor', [
            '--strict' => true,
        ])
            ->expectsOutputToContain('[INFO] [queue_connection_missing]')
            ->doesntExpectOutputToContain('[WARN] [queue_connection_missing]')
            ->assertSuccessful();
    }

    public function testQueueModeSyncQueueIsReportedAsErrorAndFailsStrict(): void
    {
        // The flip side of the poll-mode relaxation: in default queue mode
        // a sync driver is an error-severity capability failure. The doctor
        // command must render it with [ERROR] and --strict must fail.
        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'queue');

        $this->artisan('workflow:v2:doctor', [
            '--strict' => true,
        ])
            ->expectsOutputToContain('[ERROR] [queue_sync_unsupported]')
            ->assertFailed();
    }
}
