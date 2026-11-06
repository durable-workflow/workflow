<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Contracts\PreparedLocalActivityRetryTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerProtocolVersion;

final class PreparedLocalActivityRetryTaskBridgeTest extends TestCase
{
    public function testOptionalRoleAddsOnlyTheFiveExplicitVersionedOperations(): void
    {
        $role = new ReflectionClass(PreparedLocalActivityRetryTaskBridge::class);
        $this->assertTrue($role->isInterface());
        $this->assertTrue($role->isSubclassOf(PreparedLocalActivityTaskBridge::class));
        $newMethods = [];
        foreach ($role->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $role->getName()) {
                $newMethods[] = $method->getName();
            }
        }
        sort($newMethods);
        $this->assertSame([
            'controlLocalActivityWithRetryPolicy',
            'heartbeatLocalActivityWithRetryPolicy',
            'prepareLocalActivityWithRetryPolicy',
            'recordLocalActivityRetryOutcome',
            'recoverLocalActivityWithRetryPolicy',
        ], $newMethods);
    }

    public function testVersionedOperationSignaturesKeepIdentityOrderingAndExistingProtocolDefault(): void
    {
        $role = new ReflectionClass(PreparedLocalActivityRetryTaskBridge::class);
        $identity = [
            'taskId' => 'string',
            'leaseOwner' => 'string',
            'workflowTaskAttempt' => 'int',
        ];
        $attempt = [
            'attemptId' => 'string',
            'leaseOwner' => 'string',
            'workflowTaskAttempt' => 'int',
        ];
        $protocol = [
            'protocolVersion' => 'string',
        ];
        foreach ([
            'prepareLocalActivityWithRetryPolicy' => [
                ...$identity,
                'sequence' => 'int',
                'workerAttemptId' => 'string',
                'descriptor' => 'array',
                ...$protocol,
            ],
            'recordLocalActivityRetryOutcome' => [
                ...$attempt,
                'report' => 'array',
                ...$protocol,
            ],
            'controlLocalActivityWithRetryPolicy' => [
                ...$attempt,
                'renewLease' => 'bool',
                ...$protocol,
            ],
            'heartbeatLocalActivityWithRetryPolicy' => [
                ...$attempt,
                'progress' => 'array',
                ...$protocol,
            ],
            'recoverLocalActivityWithRetryPolicy' => [
                ...$identity,
                'sequence' => 'int',
                'descriptor' => 'array',
                ...$protocol,
            ],
        ] as $name => $expected) {
            $method = $role->getMethod($name);
            $actual = [];
            foreach ($method->getParameters() as $parameter) {
                $this->assertFalse($parameter->isPassedByReference());
                $this->assertFalse($parameter->isVariadic());
                $this->assertFalse($parameter->allowsNull());
                $actual[$parameter->getName()] = (string) $parameter->getType();
                if ($parameter->getName() === 'protocolVersion') {
                    $this->assertTrue($parameter->isDefaultValueAvailable());
                    $this->assertSame(WorkerProtocolVersion::VERSION, $parameter->getDefaultValue());
                } elseif ($parameter->getName() === 'renewLease') {
                    $this->assertSame(false, $parameter->getDefaultValue());
                } elseif ($parameter->getName() === 'progress') {
                    $this->assertSame([], $parameter->getDefaultValue());
                } else {
                    $this->assertFalse($parameter->isOptional());
                }
            }
            $this->assertSame($expected, $actual, $name);
            $this->assertSame('array', (string) $method->getReturnType());
        }
    }

    public function testExistingDefaultAndCustomVersionOneBridgesDoNotGainTheOptionalRole(): void
    {
        $this->assertFalse(is_a(DefaultWorkflowTaskBridge::class, PreparedLocalActivityRetryTaskBridge::class, true));
        foreach ([WorkflowTaskBridge::class, PreparedLocalActivityTaskBridge::class] as $contract) {
            $bridge = \Mockery::mock($contract);
            $this->assertNotInstanceOf(PreparedLocalActivityRetryTaskBridge::class, $bridge);
        }
    }
}
