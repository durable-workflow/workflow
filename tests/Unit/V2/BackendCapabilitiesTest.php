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
        $originalDatabaseDefault = config('database.default');

        try {
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
        } finally {
            config()->set('database.default', $originalDatabaseDefault);
        }
    }

    public function testSnapshotIncludesFrozenReadinessContractMatrix(): void
    {
        $snapshot = BackendCapabilities::snapshot();
        $contract = $snapshot['readiness_contract'];

        $this->assertSame(1, $contract['version']);
        $this->assertSame('v2_final_contract', $contract['release_state']);
        $this->assertSame(
            'Workflow\V2\Support\BackendCapabilities::snapshot',
            $contract['surfaces']['dispatch']['authority']
        );
        $this->assertSame(
            'Workflow\V2\Support\TaskBackendCapabilities::recordClaimFailureIfUnsupported',
            $contract['surfaces']['claim']['authority']
        );
        $this->assertContains('mysql', $contract['backend_capabilities']['database']['supported_drivers']);
        $this->assertContains('pgsql', $contract['backend_capabilities']['database']['supported_drivers']);
        $this->assertSame(
            'error',
            $contract['backend_capabilities']['queue']['queue_mode']['sync_or_missing_queue_severity']
        );
        $this->assertSame(
            'info',
            $contract['backend_capabilities']['queue']['poll_mode']['sync_or_missing_queue_severity']
        );
        $this->assertSame('avro', $contract['backend_capabilities']['codec']['default_for_new_v2_runs']);
        $this->assertSame(
            'evaluated_by_backend_capabilities_snapshot',
            $contract['effective_states']['dispatch']['state']
        );
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
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame(
            900,
            $snapshot['structural_limits']['backend_adjustments']['max_single_timer_delay_seconds'] ?? null
        );
        $this->assertSame(900, $snapshot['structural_limits']['effective']['max_single_timer_delay_seconds'] ?? null);
        $this->assertContains(
            'queue_max_delay_constraint',
            array_column($snapshot['structural_limits']['issues'], 'code')
        );
    }

    public function testSqliteDatabasePublishesConcurrencyNote(): void
    {
        config()
            ->set('database.connections.sqlite.driver', 'sqlite');

        $snapshot = BackendCapabilities::snapshot(databaseConnection: 'sqlite');

        $this->assertSame(
            'limited',
            $snapshot['structural_limits']['backend_adjustments']['concurrent_write_safety'] ?? null
        );
        $this->assertContains(
            'sqlite_concurrency_note',
            array_column($snapshot['structural_limits']['issues'], 'code')
        );
    }

    public function testMysqlDatabaseDoesNotPublishConcurrencyNote(): void
    {
        config()
            ->set('database.connections.mysql.driver', 'mysql');

        $snapshot = BackendCapabilities::snapshot(databaseConnection: 'mysql');

        $this->assertArrayNotHasKey('concurrent_write_safety', $snapshot['structural_limits']['backend_adjustments']);
    }

    // ---------------------------------------------------------------
    //  Poll-mode queue relaxations (#286)
    // ---------------------------------------------------------------

    public function testPollModeAllowsSyncQueueDriver(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

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
        config()
            ->set('queue.default', null);

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
        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertFalse($snapshot['queue']['supported']);
        $this->assertFalse(BackendCapabilities::isSupported($snapshot));

        $queueIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'queue_sync_unsupported');

        $this->assertNotNull($queueIssue);
        $this->assertSame('error', $queueIssue['severity']);
    }

    public function testJsonCodecConfigEmitsDecodeOnlyWarningAndAvroRemainsDefault(): void
    {
        config()->set('workflows.serializer', 'json');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('avro', $snapshot['codec']['canonical']);
        $this->assertSame('json', $snapshot['codec']['configured_canonical']);
        $this->assertTrue($snapshot['codec']['universal']);
        $this->assertFalse($snapshot['codec']['configured_universal']);
        $this->assertTrue($snapshot['codec']['supported']);

        $codecIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'codec_json_decode_only');

        $this->assertNotNull($codecIssue);
        $this->assertSame('warning', $codecIssue['severity']);
        $this->assertSame('codec', $codecIssue['component']);
        $this->assertStringContainsString('Avro only', $codecIssue['message']);
    }

    public function testLegacyPhpCodecEmitsPolyglotCompatibilityWarning(): void
    {
        config()->set('workflows.serializer', \Workflow\Serializers\Y::class);

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('avro', $snapshot['codec']['canonical']);
        $this->assertSame('workflow-serializer-y', $snapshot['codec']['configured_canonical']);
        $this->assertTrue($snapshot['codec']['universal']);
        $this->assertFalse($snapshot['codec']['configured_universal']);

        $codecIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'codec_legacy_php_only');

        $this->assertNotNull($codecIssue);
        $this->assertSame('warning', $codecIssue['severity']);
        $this->assertSame('codec', $codecIssue['component']);
        $this->assertStringContainsString('workflow-serializer-y', $codecIssue['message']);

        // A legacy-codec warning must not fail the codec component itself:
        // final v2 still starts new runs with Avro, and the configured legacy
        // value remains a diagnostic for v1 drain/import reads. Other
        // components in the test env may still fail, so assert only on the
        // codec component's own `supported` flag here.
        $this->assertTrue($snapshot['codec']['supported']);
    }

    public function testUnknownCodecStringProducesErrorSeverity(): void
    {
        config()->set('workflows.serializer', 'not-a-real-codec');

        $snapshot = BackendCapabilities::snapshot();

        $this->assertSame('avro', $snapshot['codec']['canonical']);
        $this->assertNull($snapshot['codec']['configured_canonical']);

        $codecIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'codec_unknown');

        $this->assertNotNull($codecIssue);
        $this->assertSame('error', $codecIssue['severity']);
        $this->assertFalse(BackendCapabilities::isSupported($snapshot));
    }

    public function testUnknownCodecDiagnosticIncludesMigrationGuidance(): void
    {
        config()->set('workflows.serializer', 'App\\Custom\\V1Serializer');

        $snapshot = BackendCapabilities::snapshot();

        $codecIssue = collect($snapshot['issues'])
            ->firstWhere('code', 'codec_unknown');

        $this->assertNotNull($codecIssue);

        // The diagnostic must name the unsupported value and explain that
        // custom serializer classes are not resolvable in v2.
        $this->assertStringContainsString('App\\Custom\\V1Serializer', $codecIssue['message']);
        $this->assertStringContainsString('does not support custom serializer classes', $codecIssue['message']);

        // It must name the universal codec options an operator can migrate to.
        $this->assertStringContainsString('avro', $codecIssue['message']);
        // json is no longer recommended for new workflows (Avro-only per #334)

        // It must mention that default-codec resolution silently falls back
        // to avro so operators understand why new runs still work — and that
        // the unsupported value is being ignored.
        $this->assertStringContainsString('falls back to "avro"', $codecIssue['message']);
    }
}
