<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Orchestra\Testbench\TestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Admission\RootAuthorization;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

/** A fresh application reads the authoritative fence, not the parent's model or service state. */
final class RootReservationRecoveryProcessTest extends TestCase
{
    public function testLoadAndCloseTheOriginalRootReservation(): void
    {
        $input = $this->input();
        $identity = RootIdentity::fromArray($input['identity']);
        $store = new RootReservationStore(new class() implements RootAuthorization {
            public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string
            {
                if ($principal !== 'operator-a' || $identity->scope->application !== 'root-example'
                    || $identity->scope->namespace !== 'client-a' || $identity->scope->key !== 'order-1'
                    || $identity->scope->generation !== 'original-business-generation'
                    || $identity->workflowClass !== \Tests\Fixtures\V2\TestRetainedScopeWorkflow::class) {
                    throw new LogicException('Original root relationship refused.');
                }
                return 'root-workflow-policy-v1';
            }
        }, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->application !== 'root-example' || $scope->namespace !== 'client-a'
                    || $principal !== 'operator-a') {
                    throw new LogicException('Root workflow scope access refused.');
                }
                return 'scope-policy-v1';
            }
        });
        $reservation = $store->load($identity, 'operator-a');
        $outcome = $store->close($reservation, 'operator-a');
        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'originalRunId' => $reservation->originalRunId,
            'kind' => $outcome instanceof RootBinding ? 'admitted' : 'closed',
            'receipt' => $outcome instanceof RootBinding ? $outcome->toArray() : $outcome->closureId,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($input['identity'], $reservation->identity->toArray());
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'root_process',
            'database.connections.root_process' => $this->input()['database'],
            'workflows.storage.connection' => 'root_process',
            'logging.default' => 'null',
        ]);
    }

    private function input(): array
    {
        $path = getenv('DWF_ROOT_RESERVATION_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the original-root integration control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
