<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\WorkflowStub;

final class ScopeRecoveryTest extends TestCase
{
    public function testRecoveryIdentityIsStableAndGenerationAndNamespaceBound(): void
    {
        $a = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $this->assertSame($a->recoveryInstanceId(), ScopeIdentity::fromArray($a->toArray())->recoveryInstanceId());
        $this->assertNotSame(
            $a->recoveryInstanceId(),
            (new ScopeIdentity('shop', 'client-b', 'order-42', '1'))->recoveryInstanceId()
        );
        $this->assertNotSame(
            $a->recoveryInstanceId(),
            (new ScopeIdentity('shop', 'client-a', 'order-42', '2'))->recoveryInstanceId()
        );
    }

    public function testRequestIsBoundToItsOriginalCallerAndContent(): void
    {
        [$scope, $router] = $this->open();
        $first = $router->request($scope, 'request-1', 'operator-a', 'cancel-order');
        $again = $router->request($scope, 'request-1', 'operator-a', 'cancel-order');
        $this->assertSame($first, $again);
        $this->expectException(\LogicException::class);
        $router->request($scope, 'request-1', 'operator-b', 'cancel-order');
    }

    public function testAnActiveOriginUsesNativeCooperativeCancellation(): void
    {
        [$scope, $router, $runId] = $this->open();
        $result = $router->request($scope, 'request-1', 'operator-a', 'cancel-order');
        $this->assertSame('origin', $result['route']);
        $this->assertSame($runId, $result['runId']);
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(\Workflow\V2\Models\WorkflowInstance::query()->find($scope->recoveryInstanceId()));
    }

    public function testClosedOriginStartsOneCanonicalRecoveryForDifferentRequests(): void
    {
        [$scope, $router, $runId] = $this->open();
        \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId)->forceFill([
            'status' => 'completed',
            'closed_at' => now(),
        ])->save();
        $first = $router->request($scope, 'request-1', 'operator-a', 'cancel-order');
        $second = $router->request($scope, 'request-2', 'operator-a', 'cancel-order');
        $this->assertSame('recovery', $first['route']);
        $this->assertSame($first['runId'], $second['runId']);
        $this->assertSame(
            1,
            \Workflow\V2\Models\WorkflowRun::query()->where(
                'workflow_instance_id',
                $scope->recoveryInstanceId()
            )->count()
        );
    }

    public function testDeniedRequestCannotCloseTheScopeOrCreateRecovery(): void
    {
        [$scope, $router] = $this->open();
        try {
            $router->request($scope, 'request-1', 'intruder', 'cancel-order');
            $this->fail('An unauthorized request was accepted.');
        } catch (\LogicException) {
            $this->assertSame('open', (new ScopeRepository())->inspect($scope)['status']);
            $this->assertSame(
                0,
                \Workflow\V2\Support\ConfiguredV2Models::connection()->table('workflow_compensation_requests')->count()
            );
        }
    }

    private function open(): array
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $origin = WorkflowStub::make(TestSignalWorkflow::class, 'origin-workflow', 'client-a');
        $origin->start();
        (new ScopeRepository())->open($scope, $origin->runId());
        $authorization = new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if (! in_array($principal, ['operator-a', 'operator-b'], true) || $scope->namespace !== 'client-a') {
                    throw new \LogicException('Not authorized for this scope.');
                }
                return 'test-policy-1';
            }
        };
        return [
            $scope,
            new ScopeRecovery(new ScopeRepository(), $authorization, app(WorkflowControlPlane::class)),
            $origin->runId(),
        ];
    }
}
