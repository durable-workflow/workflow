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
}
