<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Cache\Repository;
use Tests\Support\NonLockingCacheStore;
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

    public function testJsonOutputIncludesTheMatchingRoleWakeOwner(): void
    {
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.matching_role.queue_wake_enabled', false);

        $this->artisan('workflow:v2:doctor', [
            '--json' => true,
        ])
            ->expectsOutputToContain(
                '"matching_role":{"queue_wake_enabled":false,"shape":"dedicated","wake_owner":"dedicated_repair_pass","task_dispatch_mode":"poll","partition_primitives":["connection","queue","compatibility","namespace"],"backpressure_model":"lease_ownership","discovery_limits":{"poll_batch_cap":100,"availability_ceiling_seconds":1,"wake_signal_ttl_seconds":60,"workflow_task_lease_seconds":300,"activity_task_lease_seconds":300}}'
            )
            ->assertSuccessful();
    }

    public function testJsonOutputIncludesTheLocalRoleTopologyManifest(): void
    {
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $this->artisan('workflow:v2:doctor', [
            '--json' => true,
        ])
            ->expectsOutputToContain('"topology":{"schema":"durable-workflow.v2.role-topology","version":4')
            ->assertSuccessful();
    }

    public function testHumanOutputIncludesTheMatchingRoleWakeOwner(): void
    {
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.matching_role.queue_wake_enabled', false);

        $this->artisan('workflow:v2:doctor')
            ->expectsOutputToContain(
                '[INFO] matching_role: dedicated (queue_wake_enabled=false, wake_owner=dedicated_repair_pass, task_dispatch_mode=poll)'
            )
            ->assertSuccessful();
    }

    public function testHumanOutputIncludesTheLocalRoleTopologySummary(): void
    {
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $this->artisan('workflow:v2:doctor')
            ->expectsOutputToContain(
                '[INFO] topology: embedded/application_process (execution_mode=remote_worker_protocol, roles=control_plane,matching,history_projection,scheduler,execution_plane)'
            )
            ->assertSuccessful();
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

    public function testCustomNoLockCacheStoreAppearsInJsonOutputAndStrictStillSucceeds(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('workflows.serializer', 'avro');
        $this->configureNonLockingCacheStore();

        $this->artisan('workflow:v2:doctor', [
            '--json' => true,
            '--strict' => true,
        ])
            ->expectsOutputToContain('"code":"cache_locks_unsupported"')
            ->assertSuccessful();
    }

    private function configureNonLockingCacheStore(): void
    {
        $driver = 'test-non-locking';
        $store = 'test-non-locking';

        $this->app['cache']->extend($driver, function (): Repository {
            $manager = $this;
            unset($manager);

            return new Repository(new NonLockingCacheStore());
        });

        config()
            ->set("cache.stores.{$store}.driver", $driver);
        config()
            ->set('cache.default', $store);
    }
}
