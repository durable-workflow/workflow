<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestScheduleOriginWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\ScheduleOrigin;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

/**
 * Workflow::scheduleOrigin() (durable-workflow/.github#243): the schedule fire
 * that started a run, read from the run's ScheduleTriggered history event.
 */
final class V2ScheduleOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testBackfilledFiresReadTheirEnumeratedOccurrenceOnTheFirstTaskAndOnColdReplay(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'origin-backfill',
            workflowClass: TestScheduleOriginWorkflow::class,
            cronExpression: '0 0 * * *',
            arguments: [false],
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            timezone: 'UTC',
        );

        $results = ScheduleManager::backfill(
            $schedule,
            new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-03 00:00:01', new DateTimeZone('UTC')),
        );

        $this->assertCount(3, $results);

        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $index => $day) {
            $expected = [
                'schedule_id' => 'origin-backfill',
                'occurrence_time' => $day . 'T00:00:00.000000+00:00',
                'trigger_number' => $index + 1,
            ];
            $output = $this->completedOutput($results[$index]['instance_id']);

            $this->assertSame($expected, $output['first_task']);
            $this->assertSame($expected, $output['final']);
            $this->assertColdReplayReads($output['run_id'], $expected);
        }
    }

    public function testTickedFireReadsTheJitteredFireTimeNotTheStartTime(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'origin-tick',
            workflowClass: TestScheduleOriginWorkflow::class,
            cronExpression: '* * * * *',
            arguments: [false],
            timezone: 'UTC',
        );

        // A jittered fire time, due before this tick runs.
        $fireAt = now()
            ->subSeconds(37)
            ->setMicrosecond(250000);
        $schedule->forceFill([
            'next_fire_at' => $fireAt,
        ])->save();

        $results = ScheduleManager::tick();

        $this->assertCount(1, $results);
        $expected = [
            'schedule_id' => 'origin-tick',
            'occurrence_time' => $fireAt->format('Y-m-d\TH:i:s.uP'),
            'trigger_number' => 1,
        ];
        $output = $this->completedOutput($results[0]['instance_id']);

        $this->assertSame($expected, $output['first_task']);
        $this->assertSame($expected, $output['final']);
        $this->assertColdReplayReads($output['run_id'], $expected);
    }

    public function testManualTriggerHasTheScheduleWithoutAnOccurrence(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'origin-manual',
            workflowClass: TestScheduleOriginWorkflow::class,
            cronExpression: '0 0 * * *',
            arguments: [false],
        );

        $output = $this->completedOutput(ScheduleManager::trigger($schedule));
        $expected = [
            'schedule_id' => 'origin-manual',
            'occurrence_time' => null,
            'trigger_number' => 1,
        ];

        $this->assertSame($expected, $output['first_task']);
        $this->assertColdReplayReads($output['run_id'], $expected);
    }

    public function testNonScheduledStartReadsNull(): void
    {
        $stub = WorkflowStub::make(TestScheduleOriginWorkflow::class, 'origin-plain');
        $stub->start(false);

        $output = $this->completedOutput('origin-plain');

        $this->assertNull($output['first_task']);
        $this->assertNull($output['final']);
        $this->assertColdReplayReads($output['run_id'], null);
    }

    public function testContinueAsNewSuccessorKeepsTheOrigin(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'origin-continue',
            workflowClass: TestScheduleOriginWorkflow::class,
            cronExpression: '0 0 * * *',
            arguments: [true],
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            timezone: 'UTC',
        );

        $results = ScheduleManager::backfill(
            $schedule,
            new DateTimeImmutable('2026-11-05 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-05 00:00:01', new DateTimeZone('UTC')),
        );
        $expected = [
            'schedule_id' => 'origin-continue',
            'occurrence_time' => '2026-11-05T00:00:00.000000+00:00',
            'trigger_number' => 1,
        ];
        $output = $this->completedOutput($results[0]['instance_id']);

        $successor = WorkflowRun::query()->findOrFail($output['run_id']);
        $this->assertSame(2, (int) $successor->run_number);
        $this->assertSame($expected, $output['first_task']);
        $this->assertSame($expected, $output['final']);
        $this->assertSame(
            [
                HistoryEventType::StartAccepted->value,
                HistoryEventType::WorkflowStarted->value,
                HistoryEventType::ScheduleTriggered->value,
            ],
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $successor->id)
                ->orderBy('sequence')
                ->limit(3)
                ->pluck('event_type')
                ->map(static fn ($type): string => $type instanceof HistoryEventType ? $type->value : (string) $type)
                ->all(),
        );
        $this->assertColdReplayReads($output['run_id'], $expected);
    }

    public function testUnusableMarkersAreAbsentNotGuessed(): void
    {
        $this->assertNull(ScheduleOrigin::fromPayload([]));
        $this->assertNull(ScheduleOrigin::fromPayload([
            'schedule_id' => '',
        ]));
        $this->assertNull(ScheduleOrigin::fromPayload([
            'schedule_id' => 7,
        ]));
        $this->assertNull(ScheduleOrigin::fromPayload('daily'));

        $origin = ScheduleOrigin::fromPayload([
            'schedule_id' => 'daily',
            'occurrence_time' => '2026-11-01T00:00:00',
            'trigger_number' => 0,
        ]);
        $this->assertSame('daily', $origin?->scheduleId);
        $this->assertNull($origin?->occurrenceTime);
        $this->assertNull($origin?->triggerNumber);

        $origin = ScheduleOrigin::fromPayload([
            'schedule_id' => 'daily',
            'occurrence_time' => 'yesterday',
            'trigger_number' => '3',
        ]);
        $this->assertNull($origin?->occurrenceTime);
        $this->assertNull($origin?->triggerNumber);

        $origin = ScheduleOrigin::fromPayload([
            'schedule_id' => 'daily',
            'occurrence_time' => '2026-11-01T00:00:00Z',
            'trigger_number' => 3,
        ]);
        $this->assertSame('2026-11-01T00:00:00+00:00', $origin?->occurrenceTime?->format(DATE_ATOM));
        $this->assertSame(3, $origin?->triggerNumber);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedOutput(?string $instanceId): array
    {
        $this->assertNotNull($instanceId);
        $this->drainReadyTasks();
        $stub = WorkflowStub::load($instanceId);
        $stub->refresh();
        $this->assertTrue($stub->completed(), sprintf('run is %s', $stub->status()));

        return $stub->output();
    }

    /**
     * A cold replay from the exported history reads the identical origin on
     * the first task and afterwards.
     *
     * @param array<string, mixed>|null $expected
     */
    private function assertColdReplayReads(string $runId, ?array $expected): void
    {
        $export = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));
        $state = (new WorkflowReplayer())->replayExport(json_decode(json_encode($export), true));

        $this->assertInstanceOf(TestScheduleOriginWorkflow::class, $state->workflow);
        $this->assertSame($expected, $state->workflow->firstTask);
        $this->assertSame($expected, TestScheduleOriginWorkflow::describe($state->workflow->scheduleOrigin()));
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
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

        $this->fail('Ready tasks did not drain.');
    }
}
