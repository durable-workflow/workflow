<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;

final class V2WorkflowTaskBridgeTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    public function testBridgeIsResolvableFromContainer(): void
    {
        $bridge = $this->app->make(WorkflowTaskBridge::class);

        $this->assertInstanceOf(DefaultWorkflowTaskBridge::class, $bridge);
    }

    public function testPollReturnsReadyWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(1, $results);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame('redis', $results[0]['connection']);
        $this->assertSame('default', $results[0]['queue']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
    }

    public function testPollExcludesNonWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollExcludesFutureAvailableTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinutes(5),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersbyQueue(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'other-queue');

        $this->assertCount(0, $results);
    }

    public function testPollWithNullFiltersReturnsAllReadyTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null);

        $this->assertCount(1, $results);
    }

    public function testClaimStatusClaimsReadyTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);

        $task->refresh();
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame('server-worker-1', $task->lease_owner);
    }

    public function testClaimStatusRejectsNonExistentTask(): void
    {
        $result = $this->bridge->claimStatus('nonexistent-task-id');

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    public function testClaimStatusRejectsAlreadyLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_claimable', $result['reason']);
    }

    public function testClaimStatusRejectsTaskOnTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('run_closed', $result['reason']);
    }

    public function testClaimReturnsNullOnFailure(): void
    {
        $result = $this->bridge->claim('nonexistent-task-id');

        $this->assertNull($result);
    }

    public function testClaimReturnsPayloadOnSuccess(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claim($task->id, 'worker-1');

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertArrayNotHasKey('reason', $result);
    }

    public function testHistoryPayloadReturnsRunHistory(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, \Workflow\V2\Enums\HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        $result = $this->bridge->historyPayload($task->id);

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $result['workflow_type']);
        $this->assertCount(1, $result['history_events']);
        $this->assertSame('WorkflowStarted', $result['history_events'][0]['event_type']);
    }

    public function testHistoryPayloadReturnsNullForMissingTask(): void
    {
        $result = $this->bridge->historyPayload('nonexistent');

        $this->assertNull($result);
    }

    public function testHistoryPayloadPaginatedReturnsFirstPage(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
                'sequence' => $i,
                'result' => "value-{$i}",
            ], $task);
        }

        $result = $this->bridge->historyPayloadPaginated($task->id, 0, 3);

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame(0, $result['after_sequence']);
        $this->assertSame(3, $result['page_size']);
        $this->assertTrue($result['has_more']);
        $this->assertNotNull($result['next_after_sequence']);
        $this->assertCount(3, $result['history_events']);
        $this->assertSame(1, $result['history_events'][0]['sequence']);
        $this->assertSame(3, $result['history_events'][2]['sequence']);
    }

    public function testHistoryPayloadPaginatedReturnsLastPage(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
                'sequence' => $i,
                'result' => "value-{$i}",
            ], $task);
        }

        $result = $this->bridge->historyPayloadPaginated($task->id, 3, 3);

        $this->assertNotNull($result);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_after_sequence']);
        $this->assertCount(2, $result['history_events']);
        $this->assertSame(4, $result['history_events'][0]['sequence']);
        $this->assertSame(5, $result['history_events'][1]['sequence']);
    }

    public function testHistoryPayloadPaginatedReturnsEmptyPageBeyondEnd(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        $result = $this->bridge->historyPayloadPaginated($task->id, 999, 10);

        $this->assertNotNull($result);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_after_sequence']);
        $this->assertCount(0, $result['history_events']);
    }

    public function testHistoryPayloadPaginatedReturnsNullForMissingTask(): void
    {
        $result = $this->bridge->historyPayloadPaginated('nonexistent');

        $this->assertNull($result);
    }

    public function testHistoryPayloadPaginatedClampsPageSize(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        // Request page_size of 5000, should be clamped to MAX_HISTORY_PAGE_SIZE
        $result = $this->bridge->historyPayloadPaginated($task->id, 0, 5000);

        $this->assertNotNull($result);
        $this->assertSame(1000, $result['page_size']);
    }

    public function testFailRecordsTaskFailure(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, 'Worker crashed');

        $this->assertTrue($result['recorded']);
        $this->assertNull($result['reason']);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Worker crashed', $task->last_error);
        $this->assertNull($task->lease_expires_at);
    }

    public function testFailWithThrowable(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, new RuntimeException('Replay failed'));

        $this->assertTrue($result['recorded']);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Replay failed', $task->last_error);
    }

    public function testFailRejectsCompletedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, 'Late failure');

        $this->assertFalse($result['recorded']);
        $this->assertSame('task_not_active', $result['reason']);
    }

    public function testHeartbeatExtendsLease(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_expires_at' => now()
                ->addMinute(),
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertTrue($result['renewed']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);
        $this->assertSame('leased', $result['task_status']);

        $task->refresh();
        $this->assertTrue($task->lease_expires_at->isAfter(now()->addMinutes(4)));
    }

    public function testHeartbeatRejectsNonLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertFalse($result['renewed']);
        $this->assertSame('task_not_leased', $result['reason']);
    }

    public function testHeartbeatRejectsTaskOnTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_expires_at' => now()
                ->addMinute(),
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertFalse($result['renewed']);
        $this->assertSame('run_closed', $result['reason']);
        $this->assertSame('cancelled', $result['run_status']);
    }

    public function testClaimStatusRejectsActivityTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_workflow', $result['reason']);
    }

    // --- poll() compatibility filter ---

    public function testPollFiltersByCompatibility(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-b',
        ]);

        $results = $this->bridge->poll(null, null, 10, 'build-a');

        $this->assertCount(1, $results);
        $this->assertSame('build-a', $results[0]['compatibility']);
    }

    public function testPollWithNullCompatibilityReturnsAll(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-b',
        ]);

        $results = $this->bridge->poll(null, null, 10, null);

        $this->assertCount(2, $results);
    }

    // --- complete() ---

    public function testCompleteWithWorkflowCompletionClosesRun(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('Hello, Taylor'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertNull($result['reason']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('completed', $run->closed_reason);
        $this->assertNotNull($run->closed_at);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $completionEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->first();

        $this->assertNotNull($completionEvent);
    }

    public function testCompleteWithWorkflowFailureFailsRun(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'Determinism violation',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('failed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('failed', $run->closed_reason);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);

        $failureEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->first();

        $this->assertNotNull($failureEvent);
        $this->assertSame('Determinism violation', $failureEvent->payload['message']);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame('Determinism violation', $failure->message);
        $this->assertSame(RuntimeException::class, $failure->exception_class);
    }

    public function testCompleteRejectsNonLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('task_not_leased', $result['reason']);
    }

    public function testCompleteRejectsEmptyCommands(): void
    {
        $result = $this->bridge->complete('any-task', []);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteRejectsMultipleTerminalCommands(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'complete_workflow',
            ],
            [
                'type' => 'fail_workflow',
                'message' => 'oops',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteRejectsTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => '"done"',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('run_already_closed', $result['reason']);
    }

    public function testCompleteRejectsNonExistentTask(): void
    {
        $result = $this->bridge->complete('nonexistent', [
            [
                'type' => 'complete_workflow',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    // --- complete() with non-terminal commands ---

    public function testCompleteRecordsSideEffectHistory(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $serialized = Serializer::serialize(['seed' => 123]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_side_effect',
                'result' => $serialized,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->payload['sequence']);
        $this->assertSame($serialized, $event->payload['result']);
    }

    public function testCompleteRecordsVersionMarkerBeforeWorkflowCompletion(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_version_marker',
                'change_id' => 'external-step',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 2,
            ],
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('done'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(HistoryEventType::VersionMarkerRecorded, $events[0]->event_type);
        $this->assertSame('external-step', $events[0]->payload['change_id']);
        $this->assertSame(2, $events[0]->payload['version']);
        $this->assertSame(HistoryEventType::WorkflowCompleted, $events[1]->event_type);
    }

    public function testCompleteUpsertsSearchAttributesAndProjectsSummary(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'search_attributes' => [
                'remove_me' => 'legacy',
                'tenant' => 'acme',
            ],
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'env' => 'staging',
                    'remove_me' => null,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $this->assertSame([
            'env' => 'staging',
            'tenant' => 'acme',
        ], $run->search_attributes);

        $summary = WorkflowRunSummary::query()
            ->whereKey($run->id)
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame($run->search_attributes, $summary->search_attributes);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->payload['sequence']);
        $this->assertSame([
            'env' => 'staging',
            'remove_me' => null,
        ], $event->payload['attributes']);
        $this->assertSame($run->search_attributes, $event->payload['merged']);
    }

    public function testCompleteRejectsMalformedRecordVersionMarkerCommand(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'record_version_marker',
                'change_id' => 'external-step',
                'version' => 3,
                'min_supported' => 1,
                'max_supported' => 2,
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteSchedulesActivity(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => Serializer::serialize(['Taylor']),
                'queue' => 'activities',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertNull($result['reason']);
        $this->assertCount(1, $result['created_task_ids']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('test-greeting-activity', $execution->activity_type);
        $this->assertSame(ActivityStatus::Pending->value, $execution->status);
        $this->assertSame('activities', $execution->queue);

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertNotNull($activityTask);
        $this->assertSame(TaskStatus::Ready->value, $activityTask->status);
        $this->assertSame('activities', $activityTask->queue);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($execution->id, $scheduledEvent->payload['activity_execution_id']);
    }

    public function testCompleteSchedulesTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame(TimerStatus::Pending->value, $timer->status);
        $this->assertSame(300, $timer->delay_seconds);
        $this->assertNotNull($timer->fire_at);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertSame(TaskStatus::Ready->value, $timerTask->status);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($timer->id, $scheduledEvent->payload['timer_id']);
    }

    public function testCompleteStartsChildWorkflow(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);

        $childRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($childRun);
        $this->assertSame(RunStatus::Pending->value, $childRun->status);
        $this->assertSame('test-greeting-workflow', $childRun->workflow_type);

        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($childTask);
        $this->assertSame(TaskStatus::Ready->value, $childTask->status);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($link->child_workflow_run_id, $scheduledEvent->payload['child_workflow_run_id']);

        $childStartedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();

        $this->assertNotNull($childStartedEvent);
    }

    public function testCompleteContinuesAsNew(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['new-args']),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('continued', $run->closed_reason);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->first();

        $this->assertNotNull($link);

        $continuedRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($continuedRun);
        $this->assertSame(RunStatus::Pending->value, $continuedRun->status);
        $this->assertSame($run->run_number + 1, $continuedRun->run_number);
        $this->assertSame($run->workflow_instance_id, $continuedRun->workflow_instance_id);

        $continuedTask = WorkflowTask::query()
            ->where('workflow_run_id', $continuedRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($continuedTask);
        $this->assertSame(TaskStatus::Ready->value, $continuedTask->status);

        $continuedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
            ->first();

        $this->assertNotNull($continuedEvent);
        $this->assertSame($continuedRun->id, $continuedEvent->payload['continued_to_run_id']);
    }

    public function testCompleteWithMultipleNonTerminalCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-a',
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-b',
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $activityExecutions = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->get();

        $this->assertCount(2, $activityExecutions);

        $activityTasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->get();

        $this->assertCount(2, $activityTasks);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->first();

        $this->assertNotNull($timerTask);
    }

    public function testCompleteWithNonTerminalAndTerminalCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'fire-and-forget-activity',
            ],
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('done'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($execution);
    }

    public function testCompleteRejectsOnlyUnrecognizedCommands(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'unknown_command',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testScheduleActivityUsesRunDefaultsForConnectionAndQueue(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-activity',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertSame($run->connection, $execution->connection);
        $this->assertSame($run->queue, $execution->queue);
    }

    public function testCompleteThenActivityPollReturnsSameTickTasks(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => Serializer::serialize(['Taylor']),
                'queue' => 'default',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        // Poll for activity tasks immediately — same-tick tasks must be visible.
        $activityBridge = $this->app->make(\Workflow\V2\Contracts\ActivityTaskBridge::class);
        $polled = $activityBridge->poll('redis', 'default');

        $this->assertNotEmpty($polled, 'Same-tick activity tasks must be visible to ActivityTaskBridge::poll().');

        $polledTaskIds = array_column($polled, 'task_id');
        $this->assertContains($result['created_task_ids'][0], $polledTaskIds);
    }

    public function testCompleteReturnsCreatedTaskIdsForMixedCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-a',
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-b',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(3, $result['created_task_ids']);

        // Verify each created_task_id points to a real task.
        foreach ($result['created_task_ids'] as $createdId) {
            $this->assertNotNull(WorkflowTask::query()->find($createdId));
        }
    }

    public function testCompleteReturnsEmptyCreatedTaskIdsOnRejection(): void
    {
        $run = $this->createWaitingRun();

        $result = $this->bridge->complete('nonexistent-task', [
            ['type' => 'complete_workflow', 'result' => null],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame([], $result['created_task_ids']);
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return $task;
    }

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
