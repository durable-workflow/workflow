<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Recovery\ChildRecoveryRequest;

final class ChildRecoveryRequestTest extends TestCase
{
    public function testRetriesWithinTheSameNativeRunKeepTheOriginalChildRecoveryRoute(): void
    {
        $first = ChildRecoveryRequest::key('original-obligation', 'parent-recovery-1');
        $this->assertSame($first, ChildRecoveryRequest::key('original-obligation', 'parent-recovery-1'));
        $this->assertNotSame($first, ChildRecoveryRequest::key('original-obligation', 'parent-recovery-2'));
        $this->assertNotSame($first, ChildRecoveryRequest::key('another-obligation', 'parent-recovery-1'));
        $this->assertLessThanOrEqual(512, strlen($first));
    }

    public function testLegacyDirectResolverCallersKeepTheirOriginalKey(): void
    {
        $this->assertSame('parent-original-obligation', ChildRecoveryRequest::key('original-obligation', null));
    }
}
