<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\TestScopedOperation;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Jobs\RunActivityTask;

/** The parent test supplies one disposable native task; this worker dies after its provider write. */
final class CompensationForwardCrashProcessTest extends TestCase
{
    public function testCrashAfterProviderCommitBeforeForwardResult(): void
    {
        $input = $this->input();
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new LogicException('Crash-control scope access refused.');
                }
                return 'test-policy-1';
            }
        });
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new class($input['report']) implements ScopedOperation {
                    public function __construct(
                        private readonly string $report
                    ) {
                    }

                    public function assertInput(array $input): void
                    {
                        if ($input !== [
                            'mode' => 'worker-crash',
                        ]) {
                            throw new LogicException('The crash control accepts only its fixed operation.');
                        }
                    }

                    public function execute(OperationContext $context): ForwardResult
                    {
                        // Redis has acknowledged the actual provider write. No DW outcome is returned or recorded.
                        (new TestScopedOperation())->execute($context);
                        file_put_contents($this->report, json_encode([
                            'pid' => getmypid(),
                            'operationId' => $context->operationId,
                            'attemptId' => $context->attemptId,
                        ], JSON_THROW_ON_ERROR));
                        posix_kill(getmypid(), SIGKILL);
                        throw new LogicException('The worker-loss signal did not terminate this process.');
                    }

                    public function compensate(OperationContext $context): Resolution
                    {
                        throw new LogicException('The crashed forward worker cannot perform recovery.');
                    }
                },
            ],
        ]));
        (new RunActivityTask($input['task_id']))->handle();
        $this->fail('The crash control must not return from its forward activity.');
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

    private function input(): array
    {
        $path = getenv('DWF_SCOPE_FORWARD_CRASH_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the retained-scope worker-loss integration control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
