<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ClosingRunOpenWork;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\TaskWatchdog;

/**
 * Timeout cleanup for a run whose workflow tasks a bridge worker runs (#863).
 * The watchdog enters cleanup under the run lock, so such a run is not woken
 * again and again at its work deadline, and abandon-policy activities it
 * schedules during cleanup follow the #1117 rule.
 */
final class V2TimeoutCleanupServiceModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    public function testTheWatchdogEntersCleanupOnceAndStopsWakingTheRun(): void
    {
        $this->travelTo(now()->startOfSecond());
        $run = $this->newServiceRun();

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$run->id]);

        $started = $this->events($run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted);
        $this->assertCount(1, $started);
        $this->assertSame(now()->toIso8601String(), $started->first()->payload['detected_at']);
        $this->assertTrue($run->refresh()->cancellation_deadline_at->equalTo($run->execution_deadline_at));

        // The bridge worker finishes its turn without closing the run.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $task->forceFill([
            'status' => TaskStatus::Completed,
        ])->save();

        $this->travel(2)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertCount(1, $this->events($run->id)->where(
            'event_type',
            HistoryEventType::WorkflowTimeoutCleanupStarted,
        ));
    }

    public function testAnAbandonActivityScheduledDuringCleanupIsListedAndKeepsRunning(): void
    {
        /** @var WorkflowTaskBridge $bridge */
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        /** @var ActivityTaskBridge $activities */
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->travelTo(now()->startOfSecond());
        $run = $this->newServiceRun();

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$run->id]);
        WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->update([
                'status' => TaskStatus::Completed->value,
            ]);

        $commands = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'tests.cancelled-at-timeout',
                'arguments' => Serializer::serialize(['cancelled']),
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'tests.abandoned-at-timeout',
                'arguments' => Serializer::serialize(['abandoned']),
                'schedule_to_close_timeout' => 180,
                'cancellation_policy' => 'abandon',
            ],
        ], '1.20');
        $this->assertTrue($bridge->complete($this->newLease($run)->id, $commands)['completed']);

        // The hard deadline passes while cleanup is still running.
        $this->travel(10)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertSame('timed_out', $run->closed_reason);
        [$cancelled, $abandoned] = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->all();
        $this->assertSame(ActivityStatus::Cancelled, $cancelled->status);
        $this->assertSame(ActivityStatus::Pending, $abandoned->status);

        $events = $this->events($run->id);
        $terminal = $events->last();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame('expired', $terminal->payload['timeout_cleanup']['outcome']);
        // JSON columns may reorder object keys, so compare by key.
        $this->assertEquals([[
            'activity_execution_id' => $abandoned->id,
            'sequence' => $abandoned->sequence,
        ]], $terminal->payload[ClosingRunOpenWork::ABANDONED_ACTIVITIES]);

        $abandonedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->where('payload->activity_execution_id', $abandoned->id)
            ->sole();
        $this->assertSame(TaskStatus::Ready, $abandonedTask->status);

        // The abandoned activity keeps running, but nothing reaches the closed run.
        $claim = $activities->claimStatus($abandonedTask->id, 'independent-worker');
        $this->assertTrue($claim['claimed']);
        $outcome = $activities->complete($claim['activity_attempt_id'], Serializer::serialize('independent'));
        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_closed', $outcome['reason']);
        $this->assertSame(ActivityStatus::Completed, $abandoned->refresh()->status);
        $this->assertSame($events->count(), $this->events($run->id)->count());
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkflowHistoryEvent>
     */
    private function events(string $runId): \Illuminate\Support\Collection
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->toBase();
    }

    /**
     * A bridge-driven run admitted with a 70 s execution timeout and a 10 s
     * cleanup allowance.
     */
    private function newServiceRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now(),
            'execution_deadline_at' => now()
                ->addSeconds(70),
            'timeout_cleanup_work_deadline_at' => now()
                ->addSeconds(60),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function newLease(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'service-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }
}
