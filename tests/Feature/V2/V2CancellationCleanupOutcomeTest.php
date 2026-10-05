<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestCooperativeNormalCleanupWorkflow;
use Tests\Fixtures\V2\TestCooperativeWaitCleanupWorkflow;
use Tests\Fixtures\V2\TestElapsedLocalCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2CancellationCleanupOutcomeTest extends TestCase
{
    public function testEmbeddedLocalCleanupCanOutliveThePortableRenewalWindow(): void
    {
        Carbon::setTestNow('2026-10-03T08:00:00.000000Z');
        config([
            'queue.default' => 'database',
            'workflows.v2.workflow_task_lease_seconds' => 300,
        ]);
        Queue::fake();

        try {
            $workflow = WorkflowStub::make(TestElapsedLocalCleanupWorkflow::class);
            $workflow->start();
            $runId = $workflow->runId();
            $this->assertIsString($runId);
            $this->runTask($runId, TaskType::Workflow);
            $request = $workflow->requestCancellation('embedded local cleanup', 30);
            $deadline = $request->cancellationContext()?->deadline()
                ->toISOString();
            $this->runTask($runId, TaskType::Workflow);

            $this->assertTrue($workflow->refresh()->cancelled());
            $this->assertSame([
                'cleanup' => 'cleaned',
            ], $workflow->memo());
            $outcome = $this->terminal($runId)
->payload['cancellation_cleanup'];
            $this->assertSame('completed', $outcome['outcome']);
            $this->assertSame($deadline, $outcome['cleanup_deadline_at']);
            $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityStarted)->sole();
            $this->assertSame($deadline, $started->payload['lease_expires_at']);
            $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count());
            $this->assertSame('2026-10-03T08:00:11.000000Z', now()->toISOString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testLegacyTerminalCancellationDoesNotClaimCooperativeCleanup(): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestCooperativeWaitCleanupWorkflow::class);
        $workflow->start('signal');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);

        $this->assertTrue($workflow->cancel('legacy terminal command')->accepted());
        $this->assertTrue($workflow->refresh()->cancelled());
        $this->assertArrayNotHasKey('cancellation_cleanup', $this->terminal($runId)->payload);
        $this->assertSame([], $workflow->memo());
    }

    public function testRequestDuringNormalShieldedCleanupDoesNotInventCancellationDelivery(): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestCooperativeNormalCleanupWorkflow::class);
        $workflow->start();
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);
        $request = $workflow->requestCancellation('normal cleanup already started', 30);
        $this->runTask($runId, TaskType::Workflow);
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);

        $terminal = $this->terminal($runId);
        $outcome = $terminal->payload['cancellation_cleanup'] ?? null;
        $this->assertIsArray($outcome);
        $this->assertSame('not_delivered', $outcome['outcome']);
        $this->assertSame($request->commandId(), $outcome['request_id']);
        $this->assertSame($request->cancellationContext()?->deadline()->toISOString(), $outcome['cleanup_deadline_at']);
        $this->assertNull($outcome['delivery_history_event_id']);
        $this->assertNull($outcome['delivery_sequence']);
        $workflow->refresh();
        $this->assertSame([
            'cleanup' => 'Hello, cleanup!',
        ], $workflow->memo());
        $this->assertTrue($workflow->cancelled());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->count());
    }

    public function testCompletedCleanupRetainsItsCanonicalDeliveryAndOriginalBudgetAfterDuplicate(): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestCooperativeWaitCleanupWorkflow::class);
        $workflow->start('signal');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);
        $request = $workflow->requestCancellation('completed cleanup', 30);
        $context = $request->cancellationContext();
        $this->assertNotNull($context);
        $this->runTask($runId, TaskType::Workflow);
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);

        $terminal = $this->terminal($runId);
        $delivery = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->sole();
        $outcome = $terminal->payload['cancellation_cleanup'] ?? null;
        $this->assertIsArray($outcome);
        $this->assertSame('completed', $outcome['outcome']);
        $this->assertSame($request->commandId(), $outcome['request_id']);
        $this->assertSame($context->deadline()->toISOString(), $outcome['cleanup_deadline_at']);
        $this->assertSame($delivery->id, $outcome['delivery_history_event_id']);
        $this->assertSame($delivery->payload['sequence'], $outcome['delivery_sequence']);
        $this->assertSame($workflow->run()->fresh()->closed_at->toISOString(), $outcome['finished_at']);
        $this->assertTrue($terminal->recorded_at->lt($context->deadline()));
        $workflow->refresh();
        $this->assertSame([
            'cleanup' => 'Hello, cleanup!',
        ], $workflow->memo());
        $this->assertTrue($workflow->cancelled());

        $duplicate = $workflow->requestCancellation('must not replace the outcome', 300);
        $this->assertSame($request->commandId(), $duplicate->commandId());
        $this->assertSame($context->toArray(), $duplicate->cancellationContext()?->toArray());
        $this->assertSame($terminal->payload, $this->terminal($runId)->payload);
    }

    #[DataProvider('deliveryStates')]
    public function testExpiryAtOriginalDeadlineNeverClaimsCleanupCompletion(bool $deliver): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestCooperativeWaitCleanupWorkflow::class);
        $workflow->start('signal');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);
        $request = $workflow->requestCancellation('expire cleanup', 30);
        $context = $request->cancellationContext();
        $this->assertNotNull($context);
        if ($deliver) {
            $this->runTask($runId, TaskType::Workflow);
        }

        Carbon::setTestNow($context->deadline());
        try {
            $report = TaskWatchdog::runPass(respectThrottle: false, runIds: [$runId]);
            $this->assertSame(1, $report['cancellation_deadlines_enforced']);
            $terminal = $this->terminal($runId);
            $outcome = $terminal->payload['cancellation_cleanup'] ?? null;
            $this->assertIsArray($outcome);
            $this->assertSame('deadline_expired', $outcome['outcome']);
            $this->assertSame($request->commandId(), $outcome['request_id']);
            $this->assertSame($context->deadline()->toISOString(), $outcome['cleanup_deadline_at']);
            $this->assertSame($context->deadline()->toISOString(), $outcome['finished_at']);
            $this->assertTrue($workflow->refresh()->cancelled());
            $this->assertSame([], $workflow->memo());
            $delivery = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->first();
            $this->assertSame($delivery?->id, $outcome['delivery_history_event_id']);
            $this->assertSame($delivery?->payload['sequence'] ?? null, $outcome['delivery_sequence']);
            $this->assertSame($deliver, $delivery !== null);
            $again = TaskWatchdog::runPass(respectThrottle: false, runIds: [$runId]);
            $this->assertSame(0, $again['cancellation_deadlines_enforced']);
            $this->assertSame($terminal->payload, $this->terminal($runId)->payload);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function deliveryStates(): array
    {
        return [[false], [true]];
    }

    private function terminal(string $runId): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)->sole();
    }

    private function runTask(string $runId, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
