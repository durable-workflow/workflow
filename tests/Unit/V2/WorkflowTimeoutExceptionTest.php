<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Exceptions\WorkflowTimeoutException;

final class WorkflowTimeoutExceptionTest extends TestCase
{
    public function testExecutionTimeoutFactory(): void
    {
        $exception = WorkflowTimeoutException::executionTimeout('2026-01-15T11:00:00+00:00');

        $this->assertSame('execution_timeout', $exception->timeoutKind);
        $this->assertSame('2026-01-15T11:00:00+00:00', $exception->deadlineAt);
        $this->assertStringContainsString('execution deadline expired', $exception->getMessage());
        $this->assertStringContainsString('2026-01-15T11:00:00+00:00', $exception->getMessage());
    }

    public function testRunTimeoutFactory(): void
    {
        $exception = WorkflowTimeoutException::runTimeout('2026-01-15T10:30:00+00:00');

        $this->assertSame('run_timeout', $exception->timeoutKind);
        $this->assertSame('2026-01-15T10:30:00+00:00', $exception->deadlineAt);
        $this->assertStringContainsString('run deadline expired', $exception->getMessage());
        $this->assertStringContainsString('2026-01-15T10:30:00+00:00', $exception->getMessage());
    }

    public function testExceptionClassMatchesStringReference(): void
    {
        $this->assertSame('Workflow\V2\Exceptions\WorkflowTimeoutException', WorkflowTimeoutException::class);
    }
}
