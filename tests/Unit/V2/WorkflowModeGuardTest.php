<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Log;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestUnsafeDeterminismWorkflow;
use Tests\TestCase;
use Workflow\V2\Support\WorkflowModeGuard;

final class WorkflowModeGuardTest extends TestCase
{
    public function testSilentModeSkipsScanEntirely(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'silent');
        config()->set('workflows.v2.types.workflows', [
            'test-unsafe' => TestUnsafeDeterminismWorkflow::class,
        ]);

        Log::shouldReceive('warning')->never();

        WorkflowModeGuard::check();
    }

    public function testWarnModeLogsWarningsForUnsafeWorkflows(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'warn');
        config()->set('workflows.v2.types.workflows', [
            'test-unsafe' => TestUnsafeDeterminismWorkflow::class,
        ]);

        Log::shouldReceive('warning')
            ->atLeast()
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'Replay-safety warning')
                && str_contains($message, TestUnsafeDeterminismWorkflow::class));

        WorkflowModeGuard::check();
    }

    public function testWarnModeDoesNotLogForCleanWorkflows(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'warn');
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        Log::shouldReceive('warning')->never();

        WorkflowModeGuard::check();
    }

    public function testThrowModeThrowsForUnsafeWorkflows(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'throw');
        config()->set('workflows.v2.types.workflows', [
            'test-unsafe' => TestUnsafeDeterminismWorkflow::class,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Workflow determinism guardrail failed');

        WorkflowModeGuard::check();
    }

    public function testThrowModePassesForCleanWorkflows(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'throw');
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        WorkflowModeGuard::check();

        $this->assertTrue(true);
    }

    public function testEmptyTypeMapDoesNothing(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'throw');
        config()->set('workflows.v2.types.workflows', []);

        WorkflowModeGuard::check();

        $this->assertTrue(true);
    }

    public function testNullTypeMapDoesNothing(): void
    {
        config()->set('workflows.v2.guardrails.boot', 'throw');
        config()->set('workflows.v2.types.workflows', null);

        WorkflowModeGuard::check();

        $this->assertTrue(true);
    }
}
