<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\LeaseBlockingActivity;
use Workflow\Providers\WorkflowServiceProvider;

/** Invoked only by the owner-death test against its existing isolated database. */
final class EmbeddedActivityLeaseWorkerTest extends TestCase
{
    public function testOwner(): void
    {
        $checkpoint = getenv('DW_EMBEDDED_LEASE_OWNER_CHECKPOINT');
        if (! is_string($checkpoint) || $checkpoint === '') {
            self::markTestSkipped('Invoked by the isolated activity owner-death control.');
        }
        LeaseBlockingActivity::$beforeWait = static function () use ($checkpoint): void {
            $helper = null;
            $children = preg_split('/\s+/', trim(file_get_contents('/proc/self/task/'.getmypid().'/children')));
            foreach ($children as $child) {
                $command = @file_get_contents('/proc/'.$child.'/cmdline');
                if (is_string($command) && str_contains($command, '/embedded-activity-lease.php')) {
                    $helper = (int) $child;
                }
            }
            file_put_contents($checkpoint, json_encode(['owner' => getmypid(), 'helper' => $helper], JSON_THROW_ON_ERROR));
            while (true) {
                usleep(100000);
            }
        };
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default',
            '--once' => true, '--tries' => 1, '--timeout' => 30]);
        self::fail('The owner must be killed while its original callback is running.');
    }

    public function testBriefWriter(): void
    {
        $taskId = getenv('DW_EMBEDDED_LEASE_LOCK_TASK');
        $checkpoint = getenv('DW_EMBEDDED_LEASE_OWNER_CHECKPOINT');
        if (! is_string($taskId) || $taskId === '' || ! is_string($checkpoint) || $checkpoint === '') {
            self::markTestSkipped('Invoked only by the scoped metadata-contention control.');
        }
        DB::transaction(static function () use ($taskId, $checkpoint): void {
            DB::update('UPDATE workflow_tasks SET id = id WHERE id = ?', [$taskId]);
            file_put_contents($checkpoint, 'locked');
            usleep(1400000);
        });
        self::assertTrue(true);
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        EmbeddedActivityLeaseEnvironment::configure($app, (string) getenv('DW_EMBEDDED_LEASE_OWNER_DATABASE'));
    }
}
