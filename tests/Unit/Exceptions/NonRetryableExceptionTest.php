<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception;
use Tests\NonDatabaseTestCase;
use Workflow\Exceptions\NonRetryableException;

final class NonRetryableExceptionTest extends NonDatabaseTestCase
{
    public function testException(): void
    {
        $exception = new NonRetryableException('test', 1, new Exception('test'));

        $this->assertSame('test', $exception->getMessage());
        $this->assertSame(1, $exception->getCode());
        $this->assertInstanceOf(Exception::class, $exception->getPrevious());
    }
}
