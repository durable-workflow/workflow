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
    public function testDryRunReportsBackfillableCommandContractHistoryWithoutMutatingIt(): void
    {
        $run = $this->createLegacyContractRun('command-contract-dry-run');

        $expected = [
            'dry_run' => true,
            'runs_matched' => 1,
            'command_contracts_backfilled' => 0,
            'command_contracts_would_backfill' => 1,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:backfill-command-contracts', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertArrayNotHasKey('declared_query_contracts', $started->payload);
        $this->assertArrayNotHasKey('declared_signal_contracts', $started->payload);
        $this->assertArrayNotHasKey('declared_update_contracts', $started->payload);
    }

    public function testItBackfillsMissingCommandContractsOntoWorkflowStartedHistory(): void
    {
        $run = $this->createLegacyContractRun('command-contract-backfill');

        $this->artisan('workflow:v2:backfill-command-contracts', [
            '--instance-id' => $run->workflow_instance_id,
        ])
            ->expectsOutput('Backfilled 1 command-contract history snapshot(s).')
            ->assertSuccessful();

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame(['approval-stage', 'approvalMatches'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('approval-stage', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame('stage', $started->payload['declared_query_contracts'][1]['parameters'][0]['name'] ?? null);
        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('approved-by', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame('actor', $started->payload['declared_signal_contracts'][0]['parameters'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);
        $this->assertSame('approved', $started->payload['declared_update_contracts'][0]['parameters'][0]['name'] ?? null);

        $expected = [
            'dry_run' => true,
            'runs_matched' => 1,
            'command_contracts_backfilled' => 0,
            'command_contracts_would_backfill' => 0,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:backfill-command-contracts', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
    }

    public function testItSkipsRunsWhoseWorkflowClassCannotBeResolved(): void
    {
        $run = $this->createLegacyContractRun('command-contract-unavailable', 'App\\MissingWorkflow');

        $expected = [
            'dry_run' => true,
            'runs_matched' => 1,
            'command_contracts_backfilled' => 0,
            'command_contracts_would_backfill' => 0,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:backfill-command-contracts', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
    }

    private function createLegacyContractRun(
        string $instanceId,
        string $workflowClass = TestCommandTargetWorkflow::class,
    ): WorkflowRun {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => $workflowClass,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinutes(4),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            [
                'workflow_class' => $workflowClass,
                'workflow_type' => 'test-command-target-workflow',
                'declared_signals' => ['approved-by', 'rejected-by'],
                'declared_updates' => ['mark-approved'],
            ],
        );

        return $run->refresh();
    }
}
