<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\TestScopedOperation;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowRun;

/** Started by the parent integration test without its container, static state or migration traits. */
final class CompensationScopeRecoveryProcessTest extends TestCase
{
    public function testRecoverRetainedScopeInFreshProcess(): void
    {
        $input = $this->input();
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new TestScopedOperation(),
            ],
        ]));
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new \LogicException('Cold scope access refused.');
                }
                return 'cold-policy-1';
            }
        });
        $scope = ScopeIdentity::fromArray($input['scope']);
        $result = app(ScopeRecovery::class)->request($scope, 'cold-request', 'operator-a', 'cancel');
        $started = hrtime(true);
        $progress = [$this->progress($result['runId'], -1, $started)];
        for ($step = 0; $step < 20; $step++) {
            if (app(ScopeRepository::class)->inspect($scope)['status'] === 'verified'
                && WorkflowRun::query()->findOrFail($result['runId'])->status->value === 'completed') {
                break;
            }
            // PHP 8.2/8.3 cannot enter workflow fibers from PendingCommand's destructor.
            $this->artisan('queue:work', [
                '--once' => true,
                '--queue' => 'scope-recovery-test',
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertExitCode(0)
                ->run();
            $progress[] = $this->progress($result['runId'], $step, $started);
        }
        $status = app(ScopeRepository::class)->inspect($scope)['status'];
        $diagnostic = json_encode($progress, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertSame('verified', $status, $diagnostic);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($result['runId'])->status->value, $diagnostic);
        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'status' => $status,
        ], JSON_THROW_ON_ERROR));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set([
            'workflows.v2.namespaces' => ['client-a'],
            'database.default' => 'scope_process',
            'database.connections.scope_process' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'scope_process',
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'scope_process',
                'table' => 'jobs',
                'queue' => 'scope-recovery-test',
                'retry_after' => 90,
            ],
            'cache.default' => 'redis',
            'cache.stores.redis' => $input['cache'],
            'cache.prefix' => $input['cache_prefix'],
            'logging.default' => 'null',
        ]);
    }

    private function progress(string $runId, int $step, int $started): array
    {
        $snapshot = [
            'step' => $step,
            'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
            'now' => now()
                ->toISOString(),
        ];
        try {
            $run = WorkflowRun::query()->findOrFail($runId);
            $database = DB::connection();
            $delivery = app('queue')
                ->connection($run->connection);
            if (! $delivery instanceof \Illuminate\Queue\DatabaseQueue) {
                throw new \LogicException('The cold recovery diagnostic requires its database queue.');
            }
            $physicalQueue = $delivery->getQueue($run->queue);
            $tasks = $database->table('workflow_tasks')
                ->where('workflow_run_id', $runId)
                ->orderBy('id')
                ->limit(64)
                ->get([
                    'id', 'task_type', 'status', 'connection', 'queue', 'available_at',
                    'last_error', 'last_dispatch_error', 'queue_delivery_reference',
                ]);
            $references = [];
            foreach ($tasks as $task) {
                $reference = $this->deliveryReference($task, $runId, $physicalQueue);
                if ($reference !== null) {
                    $references[$reference['job_id']] = $reference;
                }
            }
            $snapshot += [
                'run' => $run->only(['id', 'workflow_instance_id', 'status', 'connection', 'queue']),
                'queue_connection' => config('queue.default'),
                'physical_queue' => $physicalQueue,
                'maintenance' => app()
                    ->isDownForMaintenance(),
                'worker_output' => $step < 0 ? null : $this->bounded(\Illuminate\Support\Facades\Artisan::output()),
                'queue_jobs_count' => $database->table('jobs')
                    ->where('queue', $physicalQueue)
                    ->count(),
                'jobs' => $database->table('jobs')
                    ->where('queue', $physicalQueue)
                    ->whereIn('id', array_keys($references))
                    ->orderBy('id')
                    ->limit(32)
                    ->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'payload'])
                    ->map(static function (object $job) use ($references): array {
                        $payload = strlen($job->payload) <= 262144 ? json_decode($job->payload, true) : null;
                        $expected = $references[$job->id];
                        return [
                            'id' => $job->id,
                            'queue' => $job->queue,
                            'attempts' => $job->attempts,
                            'reserved_at' => $job->reserved_at,
                            'available_at' => $job->available_at,
                            'payload_sha256' => hash('sha256', $job->payload),
                            'task_id' => $expected['task_id'],
                            'expected_uuid' => $expected['payload_uuid'],
                            'payload_identity_matches' => is_array($payload)
                                && ($payload['uuid'] ?? null) === $expected['payload_uuid'],
                        ];
                    })->all(),
                'failures' => $database->table('workflow_failures')
                    ->where('workflow_run_id', $runId)
                    ->orderBy('id')
                    ->limit(4)
                    ->get([
                        'id', 'exception_class', 'failure_category', 'non_retryable', 'message', 'file', 'line',
                    ])->map(fn (object $failure): array => [
                        'id' => $failure->id,
                        'exception_class' => $failure->exception_class,
                        'failure_category' => $failure->failure_category,
                        'non_retryable' => $failure->non_retryable,
                        'message' => $this->bounded($failure->message),
                        'file' => $this->bounded($failure->file),
                        'line' => $failure->line,
                    ])->all(),
                'tasks' => $tasks->map(function (object $task) use ($runId, $physicalQueue): array {
                    $value = (array) $task;
                    foreach (['last_error', 'last_dispatch_error'] as $key) {
                        $value[$key] = $this->bounded($value[$key]);
                    }
                    $value['queue_delivery_reference'] = $this->deliveryReference($task, $runId, $physicalQueue);
                    return $value;
                })->all(),
            ];
        } catch (\Throwable $error) {
            $snapshot['diagnostic_error'] = $this->bounded($error->getMessage());
        }
        return $snapshot;
    }

    private function deliveryReference(object $task, string $runId, ?string $queue): ?array
    {
        $raw = $task->queue_delivery_reference;
        $reference = is_string($raw) && strlen($raw) <= 2048 ? json_decode($raw, true) : null;
        if (! is_array($reference) || ($reference['version'] ?? null) !== 1
            || ($reference['task_id'] ?? null) !== $task->id
            || ($reference['run_id'] ?? null) !== $runId
            || ($reference['queue'] ?? null) !== $queue
            || ! is_string($reference['job_id'] ?? null)
            || preg_match('/^[1-9]\d{0,19}$/D', $reference['job_id']) !== 1
            || ! is_string($reference['payload_uuid'] ?? null)
            || preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',
                $reference['payload_uuid']
            ) !== 1) {
            return null;
        }
        return array_intersect_key($reference, array_flip(['task_id', 'job_id', 'payload_uuid']));
    }

    private function bounded(?string $value): ?array
    {
        return $value === null ? null : [
            'length' => strlen($value),
            'sha256' => hash('sha256', $value),
            'prefix' => substr($value, 0, 256),
        ];
    }

    private function input(): array
    {
        $path = getenv('DWF_SCOPE_RECOVERY_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the retained-scope integration control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
