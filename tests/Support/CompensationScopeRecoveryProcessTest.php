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
        for ($step = 0; $step < 20 && app(ScopeRepository::class)->inspect($scope)['status'] !== 'verified'; $step++) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--queue' => 'scope-recovery-test',
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertExitCode(0);
            $progress[] = $this->progress($result['runId'], $step, $started);
        }
        $status = app(ScopeRepository::class)->inspect($scope)['status'];
        $this->assertSame('verified', $status, json_encode($progress, JSON_THROW_ON_ERROR));
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
        $run = WorkflowRun::query()->findOrFail($runId);
        $database = DB::connection();
        return [
            'step' => $step,
            'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
            'now' => now()
                ->toISOString(),
            'run' => $run->only(['id', 'workflow_instance_id', 'status', 'connection', 'queue']),
            'queue_connection' => config('queue.default'),
            'maintenance' => app()
                ->isDownForMaintenance(),
            'worker_output' => $step < 0 ? '' : substr(\Illuminate\Support\Facades\Artisan::output(), 0, 4096),
            'jobs' => $database->table('jobs')
                ->orderBy('id')
                ->limit(32)
                ->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'payload'])
                ->map(static function (object $job): array {
                    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                    return [
                        'id' => $job->id,
                        'queue' => $job->queue,
                        'attempts' => $job->attempts,
                        'reserved_at' => $job->reserved_at,
                        'available_at' => $job->available_at,
                        'payload_sha256' => hash('sha256', $job->payload),
                        'uuid' => $payload['uuid'] ?? null,
                        'job' => $payload['displayName'] ?? null,
                    ];
                })->all(),
            'tasks' => $database->table('workflow_tasks')
                ->where('workflow_run_id', $runId)
                ->orderBy('id')
                ->limit(64)
                ->get([
                    'id', 'task_type', 'status', 'connection', 'queue', 'available_at',
                    'last_error', 'last_dispatch_error', 'queue_delivery_reference',
                ])->all(),
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
