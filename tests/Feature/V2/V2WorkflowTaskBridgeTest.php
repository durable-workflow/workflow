<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkflowTaskPayload;

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

    public function testClaimStatusUsesHistoryProjectionRoleBinding(): void
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

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                $this->calls[] = ['recordActivityStarted', $run->id, $execution->id, $attempt->id, $task->id];

                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
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

    public function testExecuteClosedRunUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'closed_at' => now(),
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

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->execute($task->id);

        $this->assertTrue($result['executed']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            array_slice($customRole->calls, 0, 2),
        );
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

    public function testFailUsesHistoryProjectionRoleBinding(): void
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

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->fail($task->id, 'Worker crashed');

        $this->assertTrue($result['recorded']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
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
        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => $closedAt,
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
        $this->assertSame('cancelled', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
    }

    public function testStatusReturnsLeasedTaskMetadata(): void
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
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 2,
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame('leased', $result['task_status']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($run->workflow_instance_id, $result['workflow_instance_id']);
        $this->assertSame('worker-1', $result['lease_owner']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertFalse($result['lease_expired']);
        $this->assertSame(2, $result['attempt_count']);
        $this->assertNull($result['reason']);
    }

    public function testStatusDetectsExpiredLease(): void
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
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subMinute(),
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame('leased', $result['task_status']);
        $this->assertTrue($result['lease_expired']);
        $this->assertSame('worker-1', $result['lease_owner']);
        $this->assertNull($result['reason']);
    }

    public function testStatusReturnsReadyTaskWithNoLease(): void
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

        $result = $this->bridge->status($task->id);

        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame('ready', $result['task_status']);
        $this->assertNull($result['lease_owner']);
        $this->assertNull($result['lease_expires_at']);
        $this->assertFalse($result['lease_expired']);
        $this->assertNull($result['reason']);
    }

    public function testStatusReturnsTaskNotFound(): void
    {
        $result = $this->bridge->status('nonexistent-task-id');

        $this->assertSame('nonexistent-task-id', $result['task_id']);
        $this->assertNull($result['task_status']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    public function testStatusRejectsActivityTask(): void
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

        $result = $this->bridge->status($task->id);

        $this->assertSame('task_not_workflow', $result['reason']);
    }

    public function testStatusReturnsRunStatusFromRun(): void
    {
        $run = $this->createWaitingRun();
        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => $closedAt,
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

        $result = $this->bridge->status($task->id);

        $this->assertSame('cancelled', $result['run_status']);
        $this->assertSame('cancelled', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
        $this->assertSame('leased', $result['task_status']);
    }

    public function testStatusNormalizesNullAttemptCount(): void
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
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 0,
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertNull($result['attempt_count']);
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

    public function testPollFiltersByWorkflowType(): void
    {
        $matchingRun = $this->createWaitingRun();
        $otherRun = $this->createWaitingRun();

        $otherRun->forceFill([
            'workflow_type' => 'other-workflow-type',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $matchingRun->id,
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
            'workflow_run_id' => $otherRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 10, null, null, ['test-greeting-workflow']);

        $this->assertCount(1, $results);
        $this->assertSame($matchingRun->id, $results[0]['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
    }

    public function testPollFiltersByWorkflowTypeBeforeApplyingLimit(): void
    {
        $firstRun = $this->createWaitingRun();
        $firstRun->forceFill([
            'workflow_type' => 'other-workflow-one',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $firstRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(3),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $secondRun = $this->createWaitingRun();
        $secondRun->forceFill([
            'workflow_type' => 'other-workflow-two',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $secondRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(2),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $matchingRun = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $matchingRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 2, null, null, ['test-greeting-workflow']);

        $this->assertCount(1, $results);
        $this->assertSame($matchingRun->id, $results[0]['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
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

    public function testCompleteWorkflowCompletionUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('Hello, Taylor'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
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

    public function testCompleteWorkflowFailureUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'workflow failed',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
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
        $serialized = Serializer::serialize([
            'seed' => 123,
        ]);

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
        $this->createSearchAttribute($run, 'remove_me', 'legacy');
        $this->createSearchAttribute($run, 'tenant', 'acme');

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

    public function testCompleteUpdateCommandClosesAcceptedUpdateLifecycle(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $resultPayload = Serializer::serializeWithCodec('avro', [
            'approved' => true,
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => $resultPayload,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $update->refresh();
        $workflowCommand->refresh();
        $task->refresh();

        $this->assertSame('completed', $update->status->value);
        $this->assertSame('update_completed', $update->outcome->value);
        $this->assertSame($resultPayload, $update->result);
        $this->assertSame(1, $update->workflow_sequence);
        $this->assertNotNull($update->applied_at);
        $this->assertNotNull($update->closed_at);
        $this->assertSame('update_completed', $workflowCommand->outcome->value);
        $this->assertNotNull($workflowCommand->applied_at);
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::UpdateApplied->value,
                HistoryEventType::UpdateCompleted->value,
            ])
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(HistoryEventType::UpdateApplied, $events[0]->event_type);
        $this->assertSame($update->id, $events[0]->payload['update_id']);
        $this->assertSame(1, $events[0]->payload['sequence']);
        $this->assertSame(HistoryEventType::UpdateCompleted, $events[1]->event_type);
        $this->assertSame($resultPayload, $events[1]->payload['result']);
    }

    public function testFailUpdateCommandClosesAcceptedUpdateLifecycleWithFailure(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_update',
                'update_id' => $update->id,
                'message' => 'approval denied',
                'exception_class' => RuntimeException::class,
                'exception_type' => 'approval_denied',
                'non_retryable' => true,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $update->refresh();
        $workflowCommand->refresh();
        $task->refresh();

        $this->assertSame('failed', $update->status->value);
        $this->assertSame('update_failed', $update->outcome->value);
        $this->assertSame('approval denied', $update->failure_message);
        $this->assertSame(1, $update->workflow_sequence);
        $this->assertNotNull($update->failure_id);
        $this->assertSame('update_failed', $workflowCommand->outcome->value);
        $this->assertSame(TaskStatus::Completed, $task->status);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->findOrFail($update->failure_id);

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame($workflowCommand->id, $failure->source_id);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertTrue($failure->non_retryable);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::UpdateCompleted->value)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame(HistoryEventType::UpdateCompleted, $events[0]->event_type);
        $this->assertSame($update->id, $events[0]->payload['update_id']);
        $this->assertSame($failure->id, $events[0]->payload['failure_id']);
        $this->assertSame('approval_denied', $events[0]->payload['exception_type']);
    }

    public function testUpdateCommandRejectsMismatchedTaskResumeContext(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'update',
                'workflow_update_id' => '01MISMATCHEDUPDATE000000000',
            ],
        ])->save();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => Serializer::serializeWithCodec('avro', [
                    'approved' => true,
                ]),
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);

        $update->refresh();
        $task->refresh();

        $this->assertSame('accepted', $update->status->value);
        $this->assertSame(TaskStatus::Leased, $task->status);

        $historyEventCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count();

        $this->assertSame(0, $historyEventCount);
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
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame('activities', $execution->queue);

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertNotNull($activityTask);
        $this->assertSame(TaskStatus::Ready, $activityTask->status);
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
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(300, $timer->delay_seconds);
        $this->assertNotNull($timer->fire_at);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertSame(TaskStatus::Ready, $timerTask->status);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($timer->id, $scheduledEvent->payload['timer_id']);
    }

    public function testTimerTaskCreatesResumeTaskWithTimerContext(): void
    {
        Queue::fake();

        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][0]);
        $timerId = $timerTask->payload['timer_id'] ?? null;

        $this->assertIsString($timerId);

        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSame('timer', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame("timer:{$timerId}", $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('timer', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($timerId, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($timerId, $resumeTask->payload['timer_id'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(HistoryEventType::TimerFired->value, $resumeTask->payload['workflow_event_type'] ?? null);
    }

    public function testRunTimerTaskUsesHistoryProjectionRoleBinding(): void
    {
        Queue::fake();

        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][0]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

        $this->assertGreaterThanOrEqual(2, count($customRole->calls));
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            array_slice($customRole->calls, 0, 2),
        );
    }

    public function testCompleteOpenConditionWaitWithoutTimeoutRecordsEventAndMarksWaiting(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'order-ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('order-ready', $event->payload['condition_key'] ?? null);
        $this->assertIsString($event->payload['condition_wait_id'] ?? null);
        $this->assertSame(1, $event->payload['sequence'] ?? null);
        $this->assertArrayNotHasKey('timeout_seconds', $event->payload);

        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testCompleteOpenConditionWaitUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'order-ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCompleteOpenConditionWaitWithTimeoutSchedulesConditionTimeoutTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'payment-cleared',
                'condition_definition_fingerprint' => 'fp-1',
                'timeout_seconds' => 45,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('payment-cleared', $opened->payload['condition_key'] ?? null);
        $this->assertSame('fp-1', $opened->payload['condition_definition_fingerprint'] ?? null);
        $this->assertSame(45, $opened->payload['timeout_seconds'] ?? null);

        $waitId = $opened->payload['condition_wait_id'] ?? null;
        $this->assertIsString($waitId);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(45, $timer->delay_seconds);
        $this->assertSame(1, $timer->sequence);

        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();

        $this->assertSame('condition_timeout', $scheduled->payload['timer_kind'] ?? null);
        $this->assertSame($waitId, $scheduled->payload['condition_wait_id'] ?? null);
        $this->assertSame('payment-cleared', $scheduled->payload['condition_key'] ?? null);
        $this->assertSame('fp-1', $scheduled->payload['condition_definition_fingerprint'] ?? null);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $timerTask->payload['timer_id'] ?? null);
        $this->assertSame($waitId, $timerTask->payload['condition_wait_id'] ?? null);
        $this->assertSame('payment-cleared', $timerTask->payload['condition_key'] ?? null);
    }

    public function testCompleteOpenConditionWaitWithZeroTimeoutDoesNotScheduleTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([], $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame(0, $opened->payload['timeout_seconds'] ?? null);
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testSignalResumeCompletionRecordsSatisfiedConditionWaitAndCancelsTimeout(): void
    {
        $run = $this->createWaitingRun();

        $openTask = $this->createLeasedTask($run);

        $openedResult = $this->bridge->complete($openTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
                'condition_definition_fingerprint' => 'condition-fp-1',
                'timeout_seconds' => 60,
            ],
        ]);

        $this->assertTrue($openedResult['completed']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $conditionWaitId = $opened->payload['condition_wait_id'] ?? null;

        $this->assertIsString($conditionWaitId);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(TaskStatus::Ready, $timerTask->status);

        $resumeTask = $this->createLeasedTask($run);
        $resumeTask->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:signal-1',
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => 'signal-1',
                'workflow_signal_id' => 'signal-1',
                'signal_name' => 'advance',
                'signal_wait_id' => 'signal-wait-1',
            ],
        ])->save();

        $completed = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'approved' => true,
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        $satisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->firstOrFail();

        $this->assertSame($conditionWaitId, $satisfied->payload['condition_wait_id'] ?? null);
        $this->assertSame('approval.ready', $satisfied->payload['condition_key'] ?? null);
        $this->assertSame('condition-fp-1', $satisfied->payload['condition_definition_fingerprint'] ?? null);
        $this->assertSame(1, $satisfied->payload['sequence'] ?? null);
        $this->assertSame($timer->id, $satisfied->payload['timer_id'] ?? null);
        $this->assertSame(60, $satisfied->payload['timeout_seconds'] ?? null);
        $this->assertSame('signal-1', $satisfied->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $satisfied->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-1', $satisfied->payload['signal_wait_id'] ?? null);

        $timer->refresh();
        $timerTask->refresh();

        $this->assertSame(TimerStatus::Cancelled, $timer->status);
        $this->assertSame(TaskStatus::Cancelled, $timerTask->status);

        $cancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $cancelled->payload['timer_id'] ?? null);
        $this->assertSame($conditionWaitId, $cancelled->payload['condition_wait_id'] ?? null);
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
        $this->assertSame(RunStatus::Pending, $childRun->status);
        $this->assertSame('test-greeting-workflow', $childRun->workflow_type);

        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($childTask);
        $this->assertSame(TaskStatus::Ready, $childTask->status);

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

    public function testCompleteStartsChildWorkflowWithParentNamespace(): void
    {
        $run = $this->createWaitingRun('production');

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

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);

        $childRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($childRun);
        $this->assertSame('production', $childRun->namespace);

        $this->assertSame(
            'production',
            WorkflowInstance::query()->whereKey($link->child_workflow_instance_id)->value('namespace'),
        );

        $this->assertSame(
            'production',
            WorkflowTask::query()
                ->where('workflow_run_id', $childRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->value('namespace'),
        );
    }

    public function testCompleteStartsChildWorkflowWithParentClosePolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'terminate',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('terminate', $link->parent_close_policy);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('terminate', $scheduledEvent->payload['parent_close_policy']);
    }

    public function testCompleteStartsChildWorkflowSnapshotsRetryPolicyAndTimeouts(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [2, 8],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'execution_timeout_seconds' => 600,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame(120, $childRun->run_timeout_seconds);
        $this->assertNotNull($childRun->execution_deadline_at);
        $this->assertNotNull($childRun->run_deadline_at);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'max_attempts' => 3,
            'backoff_seconds' => [2, 8],
            'non_retryable_error_types' => ['ValidationError'],
        ], $childCall->retry_policy);
        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ], $childCall->timeout_policy);
        $this->assertSame($childRun->id, $childCall->resolved_child_run_id);

        /** @var WorkflowHistoryEvent $scheduledEvent */
        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->firstOrFail();

        $this->assertSame($childCall->retry_policy, $scheduledEvent->payload['retry_policy']);
        $this->assertSame($childCall->timeout_policy, $scheduledEvent->payload['timeout_policy']);

        /** @var WorkflowHistoryEvent $startedEvent */
        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame($childCall->retry_policy, $startedEvent->payload['retry_policy']);
        $this->assertSame(600, $startedEvent->payload['execution_timeout_seconds']);
        $this->assertSame(120, $startedEvent->payload['run_timeout_seconds']);
    }

    public function testChildWorkflowFailureStartsRetryAttemptBeforeParentResume(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $failed = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'retryable child failure',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($failed['completed']);
        $this->assertSame('failed', $failed['run_status']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $links);

        /** @var WorkflowLink $retryLink */
        $retryLink = $links->last();

        /** @var WorkflowRun $retryRun */
        $retryRun = WorkflowRun::query()->findOrFail($retryLink->child_workflow_run_id);

        $this->assertSame(2, $retryRun->run_number);
        $this->assertSame(RunStatus::Pending, $retryRun->status);
        $this->assertSame(120, $retryRun->run_timeout_seconds);

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $retryRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $retryTask->status);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame($retryRun->id, $childCall->resolved_child_run_id);
        $this->assertSame(2, $childCall->metadata['attempt_count'] ?? null);
        $this->assertSame(
            $initialLink->child_workflow_run_id,
            $childCall->metadata['last_retry_of_child_workflow_run_id'] ?? null
        );

        $childStarts = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $childStarts);
        /** @var WorkflowHistoryEvent $latestChildStart */
        $latestChildStart = $childStarts->last();

        $this->assertSame(2, $latestChildStart->payload['retry_attempt'] ?? null);
        $this->assertSame(
            $initialLink->child_workflow_run_id,
            $latestChildStart->payload['retry_of_child_workflow_run_id'] ?? null
        );

        $parentReadyTasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();

        $this->assertSame(0, $parentReadyTasks);
    }

    public function testChildWorkflowRetryUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $customRole = $this->bindHistoryProjectionSpy();

        $failed = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'retryable child failure',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($failed['completed']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->get();

        /** @var WorkflowLink|null $retryLink */
        $retryLink = $links->last();

        $this->assertCount(2, $links);
        $this->assertNotNull($retryLink);
        $this->assertContains(['projectRun', $retryLink->child_workflow_run_id], $customRole->calls);
    }

    public function testChildWorkflowCompletionCreatesParentResumeTaskWithChildContext(): void
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

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($childTask->id, 'external-child-worker');

        $completed = $this->bridge->complete($childTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'child_result' => 'ok',
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame(1, $childCompleted->payload['sequence'] ?? null);
        $this->assertSame($link->id, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $childCompleted->payload['child_workflow_run_id'] ?? null);

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'workflow_wait_kind' => 'child',
            'open_wait_id' => sprintf('child:%s', $link->id),
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $link->child_workflow_run_id,
            'child_call_id' => $link->id,
            'child_workflow_run_id' => $link->child_workflow_run_id,
            'workflow_sequence' => 1,
            'workflow_event_type' => HistoryEventType::ChildRunCompleted->value,
        ], $parentResumeTask->payload);
    }

    public function testCompleteStartsChildWorkflowDefaultsToAbandonPolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('abandon', $link->parent_close_policy);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('abandon', $scheduledEvent->payload['parent_close_policy']);
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
        $this->assertSame(RunStatus::Pending, $continuedRun->status);
        $this->assertSame($run->run_number + 1, $continuedRun->run_number);
        $this->assertSame($run->workflow_instance_id, $continuedRun->workflow_instance_id);

        $continuedTask = WorkflowTask::query()
            ->where('workflow_run_id', $continuedRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($continuedTask);
        $this->assertSame(TaskStatus::Ready, $continuedTask->status);

        $continuedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
            ->first();

        $this->assertNotNull($continuedEvent);
        $this->assertSame($continuedRun->id, $continuedEvent->payload['continued_to_run_id']);
    }

    public function testCompleteContinueAsNewUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['new-args']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->firstOrFail();

        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $link->child_workflow_run_id]],
            $customRole->calls,
        );
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

    public function testScheduleActivitySnapshotsExternalRetryPolicyAndTimeouts(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-activity',
                'retry_policy' => [
                    'max_attempts' => 4,
                    'backoff_seconds' => [1, 5, 30],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => 10,
                'schedule_to_close_timeout' => 300,
                'heartbeat_timeout' => 15,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'max_attempts' => 4,
            'backoff_seconds' => [1, 5, 30],
            'start_to_close_timeout' => 120,
            'schedule_to_start_timeout' => 10,
            'schedule_to_close_timeout' => 300,
            'heartbeat_timeout' => 15,
            'non_retryable_error_types' => ['ValidationError'],
        ], $execution->retry_policy);
        $this->assertSame(120, $execution->activity_options['start_to_close_timeout']);
        $this->assertSame(10, $execution->activity_options['schedule_to_start_timeout']);
        $this->assertSame(300, $execution->activity_options['schedule_to_close_timeout']);
        $this->assertSame(15, $execution->activity_options['heartbeat_timeout']);
        $this->assertNotNull($execution->schedule_deadline_at);
        $this->assertNotNull($execution->schedule_to_close_deadline_at);

        /** @var WorkflowHistoryEvent $scheduled */
        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();

        $this->assertSame($execution->retry_policy, $scheduled->payload['activity']['retry_policy']);
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
            [
                'type' => 'complete_workflow',
                'result' => null,
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame([], $result['created_task_ids']);
    }

    private function bindHistoryProjectionSpy()
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        return $customRole;
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
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

    private function createWaitingRun(?string $namespace = null): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'namespace' => $namespace,
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
            'namespace' => $namespace,
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

    private function createSearchAttribute(WorkflowRun $run, string $key, mixed $value): void
    {
        $attribute = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
        ]);
        $attribute->setTypedValueWithInference($value);
        $attribute->upserted_at_sequence = 0;
        $attribute->inherited_from_parent = false;
        $attribute->save();
    }
}
