<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestUnsafeDeterminismWorkflow;
use Tests\TestCase;
use Workflow\V2\Support\WorkflowDeterminismDiagnostics;

final class WorkflowDeterminismDiagnosticsTest extends TestCase
{
    public function testCleanWorkflowReturnsCleanDiagnostics(): void
    {
        $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass(TestGreetingWorkflow::class);

        $this->assertSame('clean', $diagnostics['status']);
        $this->assertSame('live_definition', $diagnostics['source']);
        $this->assertSame([], $diagnostics['findings']);
    }

    public function testUnsafeWorkflowReturnsWarnings(): void
    {
        $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass(TestUnsafeDeterminismWorkflow::class);

        $this->assertSame('warning', $diagnostics['status']);
        $this->assertSame('live_definition', $diagnostics['source']);

        $symbols = collect($diagnostics['findings'])->pluck('symbol')->all();

        $this->assertContains('DB::table', $symbols);
        $this->assertContains('Cache::get', $symbols);
        $this->assertContains('request', $symbols);
        $this->assertContains('now', $symbols);
        $this->assertContains('random_int', $symbols);
        $this->assertContains('Str::uuid', $symbols);
    }

    public function testUnavailableWorkflowClassReturnsUnavailableDiagnostics(): void
    {
        $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass('Missing\\Workflow');

        $this->assertSame('unavailable', $diagnostics['status']);
        $this->assertSame('unavailable', $diagnostics['source']);
        $this->assertSame([], $diagnostics['findings']);
    }
}
