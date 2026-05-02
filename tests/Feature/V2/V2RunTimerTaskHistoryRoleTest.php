<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultHistoryProjectionRole;

/**
 * Pins every RunTimerTask projection exit to the HistoryProjectionRole binding,
 * so a future change cannot silently bypass the role for any of these paths
 * (claim-side missing-timer / unsupported-backend / unsupported-compatibility,
 * handler-side cancelled-run / cancelled-timer, or the success path that fires
 * the timer and dispatches the resume task).
 */
final class V2RunTimerTaskHistoryRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    public function testMissingTimerInClaimUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun('issue-675-history-role-missing-timer');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'timer_id' => '01J00000000000000000000XXX',
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString(
            'could not be restored from durable history',
            (string) $task->last_claim_error,
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testUnsupportedBackendInClaimUsesHistoryProjectionRoleBinding(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $run = $this->createWaitingRun('issue-675-history-role-unsupported-backend');

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

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertNull($timer->fired_at);
        $this->assertNotNull($task->last_claim_failed_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_claim_error);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCompatibilityMismatchInClaimUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun('issue-675-history-role-compat-mismatch');

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
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-z',
        ]);

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertNull($timer->fired_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCancelledRunHandlerUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun('issue-675-history-role-cancelled-run');

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
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $run->forceFill(['status' => RunStatus::Cancelled->value])->save();

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(TimerStatus::Cancelled, $timer->status);
        $this->assertNull($timer->fired_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());

        // Two projections: the successful claim leases the task and projects,
        // then the handler short-circuits on the cancelled run and projects again.
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            $customRole->calls,
        );
    }

    public function testCancelledTimerHandlerUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun('issue-675-history-role-cancelled-timer');

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
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $timer->forceFill(['status' => TimerStatus::Cancelled->value])->save();

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(TimerStatus::Cancelled, $timer->status);
        $this->assertNull($timer->fired_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            $customRole->calls,
        );
    }

    public function testTimerFiredHandlerUsesHistoryProjectionRoleBinding(): void
    {
        Queue::fake();

        $run = $this->createWaitingRun('issue-675-history-role-fired');

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
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        $this->app->call([new RunTimerTask($task->id), 'handle']);

        $task->refresh();
        $timer->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(TimerStatus::Fired, $timer->status);
        $this->assertNotNull($timer->fired_at);

        $firedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->first();

        $this->assertNotNull($firedEvent);
        $this->assertSame($timer->id, $firedEvent->payload['timer_id'] ?? null);

        // The successful claim leases the task and projects (1), then the
        // handler fires the timer + creates the resume task and projects (2).
        // The downstream TaskDispatcher::dispatch of the resume task may add
        // a third projection on successful publication, so accept >= 2 and
        // pin only the first two on this run id.
        $this->assertGreaterThanOrEqual(2, count($customRole->calls));
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            array_slice($customRole->calls, 0, 2),
        );
    }

    /**
     * @return object{calls: array<int, array{0: string, 1: string}>}
     */
    private function bindRecordingRole(): object
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            /** @var array<int, array{0: string, 1: string}> */
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

        return $customRole;
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
}
