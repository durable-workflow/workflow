<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\BusinessStateMachines\ExampleOwnershipPolicy;
use DurableWorkflow\Examples\BusinessStateMachines\NestedCompensationExample;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Orchestra\Testbench\TestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Jobs\RunActivityTask;

require_once __DIR__ . '/../../examples/business-state-machines/bootstrap.php';

/**
 * Started by the Order/Shipment example test. This worker runs one compensation
 * task and dies after the Shipment void commits, before the activity records it.
 */
final class NestedCompensationCrashProcessTest extends TestCase
{
    public function testWorkerDiesAfterTheDeliveryVoidCommits(): void
    {
        $input = $this->input();
        NestedCompensationExample::configure($this->app, DB::connection());
        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use ($input): void {
            if ($event->connection->transactionLevel() !== 0
                || ! $event->connection->table('example_machine_compensation_log')
                    ->where('action', 'deliver')
                    ->exists()) {
                return;
            }
            file_put_contents($input['report'], json_encode([
                'pid' => getmypid(),
                'task' => $input['task_id'],
            ], JSON_THROW_ON_ERROR));
            posix_kill(getmypid(), SIGKILL);
            throw new LogicException('The worker-loss signal did not terminate this process.');
        });
        (new RunActivityTask($input['task_id']))->handle();
        $this->fail('The crashed compensation worker must not return from its activity.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set([
            'workflows.v2.namespaces' => [ExampleOwnershipPolicy::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'database.default' => 'example_crash',
            'database.connections.example_crash' => $input['database'],
            'database.redis' => $input['redis'],
            'queue.default' => 'null',
            'queue.connections.null' => [
                'driver' => 'null',
            ],
            'cache.default' => 'array',
            'logging.default' => 'null',
        ]);
    }

    private function input(): array
    {
        $path = getenv('DWF_EXAMPLE_CRASH_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the Order/Shipment worker-restart example test.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
