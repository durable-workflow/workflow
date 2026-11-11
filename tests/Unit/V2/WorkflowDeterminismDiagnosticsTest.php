<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestUnsafeDeterminismWorkflow;
use Tests\Fixtures\V2\TestUnsafeImplicitTimeWorkflow;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\WorkflowDeterminismDiagnostics;

final class WorkflowDeterminismDiagnosticsTest extends NonDatabaseTestCase
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

    public function testImplicitCurrentTimeConstructorsAndShufflesAreFlagged(): void
    {
        $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass(TestUnsafeImplicitTimeWorkflow::class);

        $this->assertSame('warning', $diagnostics['status']);

        $findings = collect($diagnostics['findings']);
        $symbols = $findings->pluck('symbol')
            ->all();

        $this->assertSame(
            ['new DateTime', 'new DateTimeImmutable', 'new Carbon', 'new CarbonImmutable', 'new DateTime'],
            array_values(array_filter($symbols, static fn (string $symbol): bool => str_starts_with($symbol, 'new '))),
        );
        $this->assertContains('today', $symbols);
        $this->assertContains('array_rand', $symbols);
        $this->assertContains('str_shuffle', $symbols);
        $this->assertContains('shuffle', $symbols);
        $this->assertSame('workflow_random_call', $findings->firstWhere('symbol', 'shuffle')['rule']);
        // Fixed dates and non-literal arguments stay unflagged: five
        // constructors flagged out of eight.
        $this->assertCount(9, $symbols);
    }

    public function testUnavailableWorkflowClassReturnsUnavailableDiagnostics(): void
    {
        $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass('Missing\\Workflow');

        $this->assertSame('unavailable', $diagnostics['status']);
        $this->assertSame('unavailable', $diagnostics['source']);
        $this->assertSame([], $diagnostics['findings']);
    }
}
