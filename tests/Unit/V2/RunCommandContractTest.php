<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunCommandContract;

final class RunCommandContractTest extends TestCase
{
    public function testForRunReportsLegacyStartedEventsWithoutBackfillingOnRead(): void
    {
        $run = $this->createLegacyRun(TestCommandTargetWorkflow::class);

        $contract = RunCommandContract::forRun($run->fresh(['historyEvents']));

        $this->assertSame(RunCommandContract::SOURCE_UNAVAILABLE, $contract['source']);
        $this->assertTrue($contract['backfill_needed']);
        $this->assertTrue($contract['backfill_available']);
        $this->assertSame([], $contract['queries']);
        $this->assertSame([], $contract['signals']);
        $this->assertSame([], $contract['updates']);

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertArrayNotHasKey('declared_queries', $started->payload);
        $this->assertArrayNotHasKey('declared_signals', $started->payload);
        $this->assertArrayNotHasKey('declared_updates', $started->payload);
    }

    public function testBackfillRunExplicitlyWritesTheCommandContractOnce(): void
    {
        $run = $this->createLegacyRun(TestCommandTargetWorkflow::class);

        $result = RunCommandContract::backfillRun($run->fresh(['historyEvents']));

        $this->assertTrue($result['backfilled']);
        $this->assertSame(RunCommandContract::SOURCE_DURABLE_HISTORY, $result['source']);
        $this->assertFalse($result['backfill_needed']);
        $this->assertSame(['approval-stage', 'approvalMatches'], $result['queries']);
        $this->assertSame(['approved-by', 'rejected-by'], $result['signals']);
        $this->assertSame(['mark-approved'], $result['updates']);

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame(['approval-stage', 'approvalMatches'], $started->payload['declared_queries']);
        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals']);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates']);
        $this->assertSame('handle', $started->payload['declared_entry_method']);

        $second = RunCommandContract::backfillRun($run->fresh(['historyEvents']));

        $this->assertFalse($second['backfilled']);
        $this->assertSame(RunCommandContract::SOURCE_DURABLE_HISTORY, $second['source']);
    }

    /**
     * @param class-string $workflowClass
     */
    private function createLegacyRun(string $workflowClass): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => $workflowClass,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
            'started_at' => now(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => 'test-command-target-workflow',
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
            'workflow_type' => 'test-command-target-workflow',
        ]);

        return $run;
    }
}
