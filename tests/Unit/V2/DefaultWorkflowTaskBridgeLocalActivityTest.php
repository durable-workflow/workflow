<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class DefaultWorkflowTaskBridgeLocalActivityTest extends TestCase
{
    public function testRecordLocalActivityReconstructsAttemptTimelineAndLifecycleSnapshots(): void
    {
        Carbon::setTestNow('2026-08-28T12:00:00.000000Z');

        try {
            $run = $this->createWaitingRun();
            $task = $this->createLeasedTask($run);
            $bridge = $this->app->make(WorkflowTaskBridge::class);

            $result = $bridge->complete($task->id, [[
                'type' => 'record_local_activity',
                'activity_type' => 'mixed-worker-local-activity',
                'result' => Serializer::serialize('completed'),
                'outcome' => 'completed',
                'attempts' => [
                    [
                        'attempt_id' => 'worker-a-attempt-1',
                        'attempt_number' => 1,
                        'outcome' => 'failed',
                        'duration_ms' => 4000,
                        'message' => 'retry on another worker',
                        'retry_reason' => 'failure',
                        'backoff_seconds' => 1,
                        'heartbeats' => [[
                            'elapsed_ms' => 1500,
                            'details' => [
                                'worker' => 'worker-a',
                            ],
                        ]],
                    ],
                    [
                        'attempt_id' => 'worker-b-attempt-1',
                        'attempt_number' => 2,
                        'outcome' => 'completed',
                        'duration_ms' => 2000,
                        'heartbeats' => [[
                            'elapsed_ms' => 500,
                            'details' => [
                                'worker' => 'worker-b',
                            ],
                        ]],
                    ],
                ],
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [1],
                ],
                'execution_mode' => 'local',
            ]]);

            $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));

            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->sole();
            $attempts = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->orderBy('attempt_number')
                ->get();

            $expectedStartedAt = ['2026-08-28T11:59:53.000000Z', '2026-08-28T11:59:58.000000Z'];
            $expectedHeartbeatAt = ['2026-08-28T11:59:54.500000Z', '2026-08-28T11:59:58.500000Z'];
            $expectedClosedAt = ['2026-08-28T11:59:57.000000Z', '2026-08-28T12:00:00.000000Z'];

            $this->assertSame($expectedStartedAt, $attempts->pluck('started_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedHeartbeatAt, $attempts->pluck('last_heartbeat_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedClosedAt, $attempts->pluck('closed_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedStartedAt[0], $execution->started_at?->toJSON());
            $this->assertSame($expectedHeartbeatAt[1], $execution->last_heartbeat_at?->toJSON());
            $this->assertTrue($attempts[0]->closed_at?->copy()->addSecond()->equalTo($attempts[1]->started_at));

            $events = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->get();

            $this->assertSame([
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityCompleted,
            ], $events->pluck('event_type')
                ->all());
            $this->assertSame([
                'pending',
                'running',
                'running',
                'pending',
                'running',
                'running',
                'completed',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['activity']['status'] ?? null)
                ->all());
            $this->assertSame([
                null,
                'running',
                'running',
                'failed',
                'running',
                'running',
                'completed',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['activity_attempt']['status'] ?? null)
                ->all());
            $this->assertSame([
                null,
                'worker-a-attempt-1',
                'worker-a-attempt-1',
                'worker-a-attempt-1',
                'worker-b-attempt-1',
                'worker-b-attempt-1',
                'worker-b-attempt-1',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['worker_attempt_id'] ?? null)
                ->all());
            $this->assertSame($expectedHeartbeatAt, $events
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)
                ->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['heartbeat_at'] ?? null)
                ->values()
                ->all());

            $retry = $events->firstWhere('event_type', HistoryEventType::ActivityRetryScheduled);
            $terminal = $events->firstWhere('event_type', HistoryEventType::ActivityCompleted);

            $this->assertInstanceOf(WorkflowHistoryEvent::class, $retry);
            $this->assertInstanceOf(WorkflowHistoryEvent::class, $terminal);
            $this->assertSame($expectedHeartbeatAt[0], $retry->payload['activity']['last_heartbeat_at']);
            $this->assertSame(1, $retry->payload['retry_backoff_seconds']);
            $this->assertSame($expectedHeartbeatAt[1], $terminal->payload['activity']['last_heartbeat_at']);
            $this->assertSame($expectedClosedAt[1], $terminal->payload['activity_attempt']['closed_at']);
            $this->assertSame('worker-b-attempt-1', $terminal->payload['worker_attempt_id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createWaitingRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);
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

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
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
    }
}
