<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class V2BackfillCommandContractsCommandTest extends TestCase
{
    public function testItBackfillsAvailableLegacyCommandContractsAndLeavesUnavailableRunsVisible(): void
    {
        $availableRun = $this->createLegacyRun(TestCommandTargetWorkflow::class, 'command-contract-available');
        $unavailableRun = $this->createLegacyRun(
            'Missing\\Workflow\\CommandContractWorkflow',
            'command-contract-unavailable'
        );

        $this->artisan('workflow:v2:backfill-command-contracts', [
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertArrayNotHasKey('declared_queries', $this->startedPayload($availableRun));

        $this->artisan('workflow:v2:backfill-command-contracts')
            ->expectsOutput('Workflow v2 command-contract backfill completed.')
            ->expectsOutput('Scanned 2 run(s).')
            ->expectsOutput('Found 2 run(s) needing backfill.')
            ->expectsOutput('Backfilled 1 run(s).')
            ->expectsOutput('Skipped 1 run(s) with no available current workflow definition.')
            ->assertExitCode(0);

        $availablePayload = $this->startedPayload($availableRun);
        $this->assertSame(['approval-stage', 'approvalMatches'], $availablePayload['declared_queries']);
        $this->assertSame(['approved-by', 'rejected-by'], $availablePayload['declared_signals']);
        $this->assertSame(['mark-approved'], $availablePayload['declared_updates']);
        $this->assertArrayNotHasKey('declared_queries', $this->startedPayload($unavailableRun));
    }

    /**
     * @param class-string|string $workflowClass
     */
    private function createLegacyRun(string $workflowClass, string $workflowType): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'run_count' => 1,
            'started_at' => now(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'status' => RunStatus::Waiting->value,
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ]);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function startedPayload(WorkflowRun $run): array
    {
        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        return $started->payload;
    }
}
