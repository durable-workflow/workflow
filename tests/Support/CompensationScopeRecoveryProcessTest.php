<?php

declare(strict_types=1);

namespace Tests\Support;

use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\TestScopedOperation;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

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
        app(ScopeRecovery::class)->request($scope, 'cold-request', 'operator-a', 'cancel');
        for ($step = 0; $step < 20 && app(ScopeRepository::class)->inspect($scope)['status'] !== 'verified'; $step++) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--queue' => 'scope-recovery-test',
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertExitCode(0);
        }
        $status = app(ScopeRepository::class)->inspect($scope)['status'];
        $this->assertSame('verified', $status);
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
            'database.default' => 'scope_process',
            'database.connections.scope_process' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'scope_process',
            'workflows.v2.namespaces' => ['default', 'client-a'],
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

    private function input(): array
    {
        $path = getenv('DWF_SCOPE_RECOVERY_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the retained-scope integration control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
