<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\ActivityTaskBridge;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class V2TaskDispatchTest extends TestCase
{
    public function testTaskDispatchPersistsSuccessOnlyAfterAfterCommitPublicationRuns(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000001');

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

        DB::transaction(function () use ($task): void {
            TaskDispatcher::dispatch($task);

            /** @var WorkflowTask $insideTransaction */
            $insideTransaction = $task->fresh();

            $this->assertNull($insideTransaction->last_dispatch_attempt_at);
            $this->assertNull($insideTransaction->last_dispatched_at);
            $this->assertNull($insideTransaction->last_dispatch_error);
        });

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('workflow_task_ready', $summary->liveness_state);
    }

    public function testTaskDispatchUsesHistoryProjectionRoleBindingForSuccessfulPublication(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000001H');

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
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        TaskDispatcher::dispatch($task);

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testTaskDispatchFailureRecordsTransportFailureWithoutPretendingPublishSucceeded(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('Queue transport unavailable.'));
        });

        $run = $this->createWaitingRun('01J00000000000000000000002');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(5),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue transport unavailable.', $exception->getMessage());
        }

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNull($task->last_dispatched_at);
        $this->assertSame('Queue transport unavailable.', $task->last_dispatch_error);
        $this->assertNotNull($task->repair_available_at);
        $this->assertSame($task->last_dispatch_attempt_at?->toJSON(), $task->repair_available_at?->toJSON());

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Workflow task dispatch failed', $summary->wait_reason);
        $this->assertTrue((bool) $summary->task_problem);
        $this->assertSame('active', $summary->task_problem_badge['code']);
        $this->assertSame('Task Problem', $summary->task_problem_badge['label']);
        $this->assertSame(
            sprintf(
                'Workflow task %s could not be dispatched at %s. Queue transport unavailable.',
                $task->id,
                $task->last_dispatch_attempt_at?->toJSON(),
            ),
            $summary->liveness_reason,
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('Workflow task dispatch failed', $detail['wait_reason']);
        $this->assertTrue($detail['task_problem']);
        $this->assertSame('active', $detail['task_problem_badge']['code']);
        $this->assertTrue($detail['tasks'][0]['dispatch_failed']);
        $this->assertFalse($detail['tasks'][0]['dispatch_overdue']);
        $this->assertSame('dispatch_failed', $detail['tasks'][0]['transport_state']);
        $this->assertSame('Workflow task dispatch failed; waiting for recovery.', $detail['tasks'][0]['summary']);
        $this->assertSame(
            $task->last_dispatch_attempt_at?->toJSON(),
            $detail['tasks'][0]['last_dispatch_attempt_at']?->toJSON(),
        );
        $this->assertSame('Queue transport unavailable.', $detail['tasks'][0]['last_dispatch_error']);

        $export = HistoryExport::forRun($run->fresh(['summary']));

        $this->assertTrue($export['summary']['task_problem']);
        $this->assertSame('active', $export['summary']['task_problem_badge']['code']);
    }

    public function testTaskDispatchFailureUsesHistoryProjectionRoleBindingForRepairProjection(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('Queue transport unavailable.'));
        });

        $run = $this->createWaitingRun('01J00000000000000000000001J');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(5),
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
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue transport unavailable.', $exception->getMessage());
        }

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testTaskDispatchFailureRecordsUnsupportedQueueCapabilityWithoutRunningInline(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000003');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(5),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (UnsupportedBackendCapabilitiesException $exception) {
            $this->assertSame('sync', $exception->snapshot()['queue']['connection']);
            $this->assertContains('queue_sync_unsupported', array_column($exception->snapshot()['issues'], 'code'));
        }

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNull($task->last_dispatched_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_dispatch_error);
        $this->assertNotNull($task->repair_available_at);
        $this->assertSame($task->last_dispatch_attempt_at?->toJSON(), $task->repair_available_at?->toJSON());

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Workflow task dispatch failed', $summary->wait_reason);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('dispatch_failed', $detail['tasks'][0]['transport_state']);
        $this->assertSame($task->last_dispatch_error, $detail['tasks'][0]['last_dispatch_error']);
    }

    public function testWorkflowTaskClaimFailureRecordsUnsupportedQueueCapabilityWithoutLeasing(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $run = $this->createWaitingRun('01J00000000000000000000004');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSecond(),
        ]);

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_claim_error);
        $this->assertNotNull($task->repair_available_at);
        $this->assertTrue($task->repair_available_at->gt($task->last_claim_failed_at));

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('Workflow task claim failed', $summary->wait_reason);
        $this->assertSame('workflow_task_claim_failed', $summary->liveness_state);
        $this->assertTrue((bool) $summary->task_problem);
        $this->assertSame('active', $summary->task_problem_badge['code']);
        $this->assertStringContainsString('could not be claimed by a worker', (string) $summary->liveness_reason);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $summary->liveness_reason);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('workflow_task_claim_failed', $detail['liveness_state']);
        $this->assertTrue($detail['task_problem']);
        $this->assertSame('active', $detail['task_problem_badge']['code']);
        $this->assertTrue($detail['tasks'][0]['claim_failed']);
        $this->assertSame('repair_backoff', $detail['tasks'][0]['transport_state']);
        $this->assertSame($task->last_claim_error, $detail['tasks'][0]['last_claim_error']);
        $this->assertNotNull($detail['tasks'][0]['last_claim_failed_at']);
        $this->assertSame(
            $task->repair_available_at?->toJSON(),
            $detail['tasks'][0]['repair_available_at']?->toJSON(),
        );

        $export = HistoryExport::forRun($run->fresh(['summary']));

        $this->assertTrue($export['summary']['task_problem']);
        $this->assertSame('active', $export['summary']['task_problem_badge']['code']);
    }

    public function testActivityTaskClaimFailureRecordsUnsupportedQueueCapabilityWithoutStartingAttempt(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $run = $this->createWaitingRun('01J00000000000000000000005');

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'sync',
            'queue' => 'default',
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSecond(),
        ]);

        $this->app->call([new RunActivityTask($task->id), 'handle']);

        $task->refresh();
        $execution->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertNull($execution->current_attempt_id);
        $this->assertSame(0, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityStarted->value)
            ->count());
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_claim_error);
        $this->assertNotNull($task->repair_available_at);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity_task_claim_failed', $summary->liveness_state);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertTrue($detail['tasks'][0]['claim_failed']);
        $this->assertSame('repair_backoff', $detail['tasks'][0]['transport_state']);
    }

    public function testActivityTaskBridgeClaimFailureRecordsUnsupportedQueueCapabilityWithoutStartingAttempt(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $run = $this->createWaitingRun('01J00000000000000000000007');

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'sync',
            'queue' => 'default',
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSecond(),
        ]);

        $this->assertNull(ActivityTaskBridge::claim($task->id, 'external-worker-unsupported'));

        $task->refresh();
        $execution->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertNull($execution->current_attempt_id);
        $this->assertSame(0, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityStarted->value)
            ->count());
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_claim_error);
        $this->assertNotNull($task->repair_available_at);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity_task_claim_failed', $summary->liveness_state);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertTrue($detail['tasks'][0]['claim_failed']);
        $this->assertSame('repair_backoff', $detail['tasks'][0]['transport_state']);
    }

    public function testTimerTaskClaimFailureRecordsUnsupportedQueueCapabilityWithoutFiringTimer(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $run = $this->createWaitingRun('01J00000000000000000000006');

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 1,
            'fire_at' => now()
                ->subSecond(),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSecond(),
        ]);

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertNull($timer->fired_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_claim_error);
        $this->assertNotNull($task->repair_available_at);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('timer_task_claim_failed', $summary->liveness_state);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertTrue($detail['tasks'][0]['claim_failed']);
        $this->assertSame('repair_backoff', $detail['tasks'][0]['transport_state']);
    }

    public function testPollModeSkipsQueueDispatchAndLeavesTaskReadyForExternalPolling(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000010');

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

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);
        $this->assertNull($task->repair_available_at);
    }

    public function testPollModeSkipsQueueDispatchForActivityTasks(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000011');

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
            'compatibility' => 'build-a',
        ]);

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);
    }

    public function testPollModeSkipsQueueDispatchForTimerTasks(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000012');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinute(),
            'payload' => [
                'timer_id' => 'test-timer',
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);
    }

    public function testQueueModeStillDispatchesToBus(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'queue');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000013');

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

        TaskDispatcher::dispatch($task);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testDispatchBlocksUnderFailValidationModeWhenFleetLacksRequiredMarker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-b');

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000020');

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

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNull($task->last_dispatched_at);
        $this->assertNotNull($task->last_dispatch_error);
        $this->assertStringContainsString(
            'Dispatch blocked under fail validation mode',
            (string) $task->last_dispatch_error
        );
        $this->assertStringContainsString('build-a', (string) $task->last_dispatch_error);
        $this->assertNotNull($task->repair_available_at);
    }

    public function testDispatchProceedsUnderWarnValidationModeEvenWhenFleetLacksRequiredMarker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'warn');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-b');

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000021');

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

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testDispatchProceedsUnderFailValidationModeWhenFleetHasSupportingWorker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-a', 'build-b'], 'redis', 'default', 'worker-a');

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000022');

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

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testDispatchProceedsUnderFailValidationModeWhenFleetIsEmptyInScope(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000023');

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

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testDispatchProceedsUnderFailValidationModeWhenNoMarkerIsRequired(): void
    {
        config()->set('workflows.v2.compatibility.current', null);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-b');

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000024');
        $run->forceFill([
            'compatibility' => null,
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
            'compatibility' => null,
        ]);

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    private function createWaitingRun(string $instanceId): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
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

    private function configureUnsupportedSyncTaskConnection(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }
}
