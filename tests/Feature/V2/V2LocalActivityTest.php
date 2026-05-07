<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestLocalActivityWorkflow;
use Tests\Fixtures\V2\TestLocalHeartbeatWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\LocalActivityRuntime;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\WorkflowStub;

final class V2LocalActivityTest extends TestCase
{
    public function testLocalActivityExecutesInWorkflowTaskAndRecordsMarkedHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestLocalActivityWorkflow::class, 'local-activity-basic');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'local-activity-basic',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Activity->value)
            ->count());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->sole();

        $this->assertSame(TestGreetingActivity::class, $execution->activity_class);
        $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $execution->activity_options['execution_mode']);
        $this->assertTrue($execution->activity_options['queue_bypassed']);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityCompleted->value,
            ])
            ->orderBy('sequence')
            ->get();

        $this->assertSame([
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
        ], $events->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());

        foreach ($events as $event) {
            $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $event->payload['execution_mode'] ?? null);
            $this->assertTrue($event->payload['local_activity'] ?? false);
            $this->assertSame($execution->id, $event->payload['activity_execution_id'] ?? null);
            $this->assertIsString($event->payload['workflow_task_id'] ?? null);
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $activities = RunActivityView::activitiesForRun($run->fresh());

        $this->assertCount(1, $activities);
        $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $activities[0]['execution_mode']);
        $this->assertTrue($activities[0]['local_activity']);

        $export = HistoryExport::forRun($run->fresh(['historyEvents', 'activityExecutions.attempts']));

        $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $export['activities'][0]['execution_mode']);
        $this->assertTrue($export['activities'][0]['local_activity']);

        $metrics = OperatorMetrics::snapshot();

        $this->assertSame(1, $metrics['activities']['local']);
        $this->assertSame(1, $metrics['activities']['local_attempts']);
        $this->assertSame(0, $metrics['activities']['queued_open']);
    }

    public function testLocalActivityHeartbeatRenewsWorkflowTaskAndRecordsLocalMarker(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestLocalHeartbeatWorkflow::class, 'local-activity-heartbeat');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowHistoryEvent $heartbeat */
        $heartbeat = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
            ->sole();

        $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $heartbeat->payload['execution_mode'] ?? null);
        $this->assertTrue($heartbeat->payload['local_activity'] ?? false);
        $this->assertIsString($heartbeat->payload['workflow_task_id'] ?? null);
        $this->assertSame('Polling remote job', $heartbeat->payload['progress']['message'] ?? null);
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $cutoff = now()->format('Y-m-d H:i:s.u');

            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->where(static function ($query) use ($cutoff): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $cutoff);
                })
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
