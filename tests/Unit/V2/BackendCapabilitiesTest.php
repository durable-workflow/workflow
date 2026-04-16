<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Support\BackendCapabilities;

final class BackendCapabilitiesTest extends TestCase
{
    public function testSnapshotFlagsSyncQueueAsUnsupported(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('2026-04-09T12:00:00.000000Z', $snapshot['generated_at']);
        $this->assertFalse($snapshot['supported']);
        $this->assertSame('sync', $snapshot['queue']['connection']);
        $this->assertSame('sync', $snapshot['queue']['driver']);
        $this->assertFalse($snapshot['queue']['supported']);
        $this->assertContains('queue_sync_unsupported', array_column($snapshot['issues'], 'code'));
        $this->assertFalse(BackendCapabilities::isSupported($snapshot));
    }

    public function testSnapshotCapturesConfiguredBackendIdentities(): void
    {
        config()->set('database.default', 'pgsql');
        config()
            ->set('database.connections.pgsql.driver', 'pgsql');
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('pgsql', $snapshot['database']['connection']);
        $this->assertSame('pgsql', $snapshot['database']['driver']);
        $this->assertTrue($snapshot['database']['supported']);
        $this->assertTrue($snapshot['database']['capabilities']['row_locks']);
        $this->assertSame('redis', $snapshot['queue']['connection']);
        $this->assertSame('redis', $snapshot['queue']['driver']);
        $this->assertTrue($snapshot['queue']['supported']);
        $this->assertSame('array', $snapshot['cache']['store']);
        $this->assertSame('array', $snapshot['cache']['driver']);
        $this->assertTrue($snapshot['cache']['supported']);
        $this->assertTrue($snapshot['cache']['capabilities']['atomic_locks']);
    }

    public function testSnapshotCanInspectAnExplicitTaskQueueConnection(): void
    {
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $snapshot = BackendCapabilities::snapshot(queueConnection: 'sync');

        $this->assertFalse($snapshot['supported']);
        $this->assertSame('sync', $snapshot['queue']['connection']);
        $this->assertSame('sync', $snapshot['queue']['driver']);
        $this->assertFalse($snapshot['queue']['capabilities']['async_delivery']);
        $this->assertContains('queue_sync_unsupported', array_column($snapshot['issues'], 'code'));
    }

    public function testSnapshotDoesNotAdvertiseDatabaseCapabilitiesForUnsupportedDriver(): void
    {
        config()->set('database.connections.mongodb.driver', 'mongodb');

        $snapshot = BackendCapabilities::snapshot(databaseConnection: 'mongodb');

        $this->assertFalse($snapshot['supported']);
        $this->assertSame('mongodb', $snapshot['database']['connection']);
        $this->assertSame('mongodb', $snapshot['database']['driver']);
        $this->assertFalse($snapshot['database']['supported']);
        $this->assertContains('database_driver_unsupported', array_column($snapshot['issues'], 'code'));
        $this->assertSame([
            'transactions' => false,
            'after_commit_callbacks' => false,
            'durable_ordering' => false,
            'row_locks' => false,
        ], $snapshot['database']['capabilities']);
    }

    // ---------------------------------------------------------------
    //  Structural limits in backend contract
    // ---------------------------------------------------------------

    public function testSnapshotIncludesStructuralLimitsContract(): void
    {
        $snapshot = BackendCapabilities::snapshot();

        $this->assertArrayHasKey('structural_limits', $snapshot);
        $this->assertArrayHasKey('configured', $snapshot['structural_limits']);
        $this->assertArrayHasKey('backend_adjustments', $snapshot['structural_limits']);
        $this->assertArrayHasKey('effective', $snapshot['structural_limits']);
        $this->assertArrayHasKey('issues', $snapshot['structural_limits']);

        $this->assertArrayHasKey('pending_activity_count', $snapshot['structural_limits']['configured']);
        $this->assertArrayHasKey('warning_threshold_percent', $snapshot['structural_limits']['configured']);
    }

    public function testSqsQueuePublishesMaxDelayConstraint(): void
    {
        config()->set('queue.default', 'sqs');
        config()->set('queue.connections.sqs.driver', 'sqs');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame(900, $snapshot['structural_limits']['backend_adjustments']['max_single_timer_delay_seconds'] ?? null);
        $this->assertSame(900, $snapshot['structural_limits']['effective']['max_single_timer_delay_seconds'] ?? null);
        $this->assertContains('queue_max_delay_constraint', array_column($snapshot['structural_limits']['issues'], 'code'));
    }

    public function testSqliteDatabasePublishesConcurrencyNote(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.driver', 'sqlite');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('limited', $snapshot['structural_limits']['backend_adjustments']['concurrent_write_safety'] ?? null);
        $this->assertContains('sqlite_concurrency_note', array_column($snapshot['structural_limits']['issues'], 'code'));
    }

    public function testMysqlDatabaseDoesNotPublishConcurrencyNote(): void
    {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertArrayNotHasKey('concurrent_write_safety', $snapshot['structural_limits']['backend_adjustments']);
    }

    // ---------------------------------------------------------------
    //  Poll-mode queue relaxations (#286)
    // ---------------------------------------------------------------

    public function testPollModeAllowsSyncQueueDriver(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertTrue($snapshot['queue']['supported']);
        $this->assertTrue(BackendCapabilities::isSupported($snapshot));
        $this->assertFalse($snapshot['queue']['capabilities']['requires_worker']);

        $queueIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'queue_sync_unsupported');

        $this->assertNotNull($queueIssue, 'The queue_sync_unsupported note should still be recorded informationally.');
        $this->assertSame('info', $queueIssue['severity']);
    }

    public function testPollModeAllowsMissingQueueConnection(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()->set('queue.default', null);

        $snapshot = BackendCapabilities::snapshot();

        $this->assertTrue($snapshot['queue']['supported']);
        $this->assertTrue(BackendCapabilities::isSupported($snapshot));

        $queueIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'queue_connection_missing');

        $this->assertNotNull($queueIssue);
        $this->assertSame('info', $queueIssue['severity']);
    }

    public function testQueueModeStillRejectsSyncQueueDriver(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'queue');
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertFalse($snapshot['queue']['supported']);
        $this->assertFalse(BackendCapabilities::isSupported($snapshot));

        $queueIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'queue_sync_unsupported');

        $this->assertNotNull($queueIssue);
        $this->assertSame('error', $queueIssue['severity']);
    }
}
