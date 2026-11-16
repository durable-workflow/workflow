<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\RegisteredObligation;

final class RegisteredObligationTest extends TestCase
{
    public function testHostCodeCannotManufactureARegisteredWorkflowOperation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('recorded native workflow context');
        new RegisteredObligation(new ScopeIdentity('app', 'tenant-a', 'order-1', 'original-run'), str_repeat(
            'a',
            64
        ), 'worker');
    }

    public function testAnUninitializedHandleStillRefusesBeforeDispatchOutsideTheWorkflow(): void
    {
        $handle = (new ReflectionClass(RegisteredObligation::class))->newInstanceWithoutConstructor();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('authored inside its native workflow');
        $handle->execute();
    }

    public function testAnUninitializedCompensationHandleRefusesBeforeReadingItsIdentity(): void
    {
        $handle = (new ReflectionClass(RegisteredObligation::class))->newInstanceWithoutConstructor();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Registered compensation must be authored inside its native workflow.');
        $handle->compensate();
    }
}
