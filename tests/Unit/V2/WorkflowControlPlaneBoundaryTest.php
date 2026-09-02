<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Contracts\RuntimeSignalControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowControlPlane;

final class WorkflowControlPlaneBoundaryTest extends TestCase
{
    public function testHostAdapterImplementsOrdinaryOperationsWithoutRuntimeTransport(): void
    {
        $adapter = new class() implements WorkflowControlPlane {
            public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
            {
                return [];
            }

            public function signal(string $instanceId, string $name, array $options = []): array
            {
                return [];
            }

            public function query(string $instanceId, string $name, array $options = []): array
            {
                return [];
            }

            public function update(string $instanceId, string $name, array $options = []): array
            {
                return [];
            }

            public function cancel(string $instanceId, array $options = []): array
            {
                return [];
            }

            public function terminate(string $instanceId, array $options = []): array
            {
                return [];
            }

            public function repair(string $instanceId, array $options = []): array
            {
                return [];
            }

            public function archive(string $instanceId, array $options = []): array
            {
                return [];
            }

            public function describe(string $instanceId, array $options = []): array
            {
                return [];
            }
        };

        $this->assertInstanceOf(WorkflowControlPlane::class, $adapter);
        $this->assertNotInstanceOf(RuntimeSignalControlPlane::class, $adapter);
    }

    public function testReservedSignalDeliveryIsOutsideTheHostFacingInterface(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(WorkflowControlPlane::class))->getMethods(),
        );
        sort($methods);

        $this->assertSame([
            'archive',
            'cancel',
            'describe',
            'query',
            'repair',
            'signal',
            'start',
            'terminate',
            'update',
        ], $methods);
        $this->assertSame(
            ['runtimeSignal'],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass(RuntimeSignalControlPlane::class))->getMethods(),
            ),
        );
        $this->assertTrue(is_subclass_of(DefaultWorkflowControlPlane::class, RuntimeSignalControlPlane::class));
    }
}
