<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestConditionTimeoutInputOrderWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

/**
 * A condition wait with a timeout is decided by durable input order: input
 * accepted before the wait's condition-timeout TimerFired can satisfy it even
 * when the workflow task runs after the deadline; input accepted after it
 * cannot. Outcomes already recorded replay unchanged (.github#230).
 */
final class V2ConditionTimeoutInputOrderTest extends TestCase
{
    private const HISTORY_DIR = __DIR__ . '/../../Fixtures/V2/ConditionTimeoutInputOrder';

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testUpdateAcceptedBeforeTimeoutFiredSatisfiesWaitWhenTaskRunsAfterDeadline(): void
    {
        $workflow = $this->startWaiting(TestAwaitWithTimeoutWorkflow::class, 'g230-before-fire');

        $this->at('12:00:03');
        $this->assertTrue($workflow->submitUpdate('approve', true)->accepted());
        $this->at('12:00:06');
        $this->runReady($workflow->runId(), TaskType::Timer);
        $this->at('12:00:30');
        $this->runReady($workflow->runId(), TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertTrue($workflow->output()['approved']);
        $this->assertFalse($workflow->output()['timed_out']);

        $types = $this->eventTypes($workflow->runId());
        $this->assertLessThan(array_search('TimerFired', $types, true), array_search('UpdateAccepted', $types, true));
        $this->assertLessThan(
            array_search('ConditionWaitSatisfied', $types, true),
            array_search('TimerFired', $types, true),
        );
        $this->assertNotContains('ConditionWaitTimedOut', $types);
        $this->assertNotContains('TimerCancelled', $types);
    }

    public function testUpdateAcceptedAfterTimeoutFiredCannotRescueWait(): void
    {
        $workflow = $this->startWaiting(TestAwaitWithTimeoutWorkflow::class, 'g230-after-fire');

        $this->at('12:00:06');
        $this->runReady($workflow->runId(), TaskType::Timer);
        $this->at('12:00:08');
        $update = $workflow->submitUpdate('approve', true);
        $this->assertTrue($update->accepted());
        $this->at('12:00:30');
        $this->runReady($workflow->runId(), TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertFalse($workflow->output()['approved']);
        $this->assertTrue($workflow->output()['timed_out']);

        $types = $this->eventTypes($workflow->runId());
        $this->assertContains('ConditionWaitTimedOut', $types);
        $this->assertNotContains('ConditionWaitSatisfied', $types);
        // The late update is still applied and completed; it only cannot decide the wait.
        $this->assertContains('UpdateCompleted', $types);
    }

    public function testEqualTimestampsUseCanonicalHistoryOrder(): void
    {
        $inputFirst = $this->startWaiting(TestAwaitWithTimeoutWorkflow::class, 'g230-equal-input-first');
        $timerFirst = $this->startWaiting(TestAwaitWithTimeoutWorkflow::class, 'g230-equal-timer-first');

        $this->at('12:00:06');
        $this->assertTrue($inputFirst->submitUpdate('approve', true)->accepted());
        $this->runReady($inputFirst->runId(), TaskType::Timer);
        $this->runReady($timerFirst->runId(), TaskType::Timer);
        $this->assertTrue($timerFirst->submitUpdate('approve', true)->accepted());

        $this->at('12:00:30');
        $this->runReady($inputFirst->runId(), TaskType::Workflow);
        $this->runReady($timerFirst->runId(), TaskType::Workflow);

        $this->assertTrue($inputFirst->refresh()->output()['approved']);
        $this->assertFalse($timerFirst->refresh()->output()['approved']);
    }

    public function testSelectedTruePrefixIsNotReversedByLaterInput(): void
    {
        $workflow = $this->startWaiting(
            TestConditionTimeoutInputOrderWorkflow::class,
            'g230-true-prefix',
            'standalone'
        );

        $this->at('12:00:02');
        $this->assertTrue($workflow->submitUpdate('approve', true)->accepted());
        $this->at('12:00:03');
        $this->assertTrue($workflow->submitUpdate('approve', false)->accepted());
        $this->at('12:00:06');
        $this->runReady($workflow->runId(), TaskType::Timer);
        $this->at('12:00:30');
        $this->runReady($workflow->runId(), TaskType::Workflow);

        $this->assertSame(['approve', 'unapprove', 'condition-satisfied'], $workflow->query('currentState')['events']);

        $this->finish($workflow);

        $this->assertSame([
            'approved' => true,
            'approved_state' => false,
            'events' => ['approve', 'unapprove', 'condition-satisfied'],
        ], $workflow->refresh()
            ->output());
    }

    public function testPredicateTrueOnEntryWinsWithoutATimer(): void
    {
        $workflow = WorkflowStub::make(TestConditionTimeoutInputOrderWorkflow::class, 'g230-entry-true');
        $workflow->start('standalone');
        // The update is accepted before the first task reaches the wait, so the
        // wait starts with a true predicate.
        $this->assertTrue($workflow->submitUpdate('approve', true)->accepted());
        $this->runReady($workflow->runId(), TaskType::Workflow);

        $this->assertSame(['approve', 'condition-satisfied'], $workflow->query('currentState')['events']);
        $this->assertNotContains('TimerScheduled', $this->eventTypes($workflow->runId()));
    }

    public function testSelectConditionUsesTheSameOrderingBoundary(): void
    {
        $before = $this->startWaiting(TestConditionTimeoutInputOrderWorkflow::class, 'g230-select-before', 'select');
        $after = $this->startWaiting(TestConditionTimeoutInputOrderWorkflow::class, 'g230-select-after', 'select');

        $this->at('12:00:03');
        $this->assertTrue($before->submitUpdate('approve', true)->accepted());
        $this->at('12:00:06');
        $this->runReady($before->runId(), TaskType::Timer);
        $this->runReady($after->runId(), TaskType::Timer);
        $this->at('12:00:08');
        $this->assertTrue($after->submitUpdate('approve', true)->accepted());
        $this->at('12:00:30');
        $this->drainWorkflowTasks($before->runId());
        $this->drainWorkflowTasks($after->runId());

        $this->assertSame(['approve', 'condition-timed-out'], $after->query('currentState')['events']);
        $this->assertSame(['approve', 'condition-satisfied'], $before->query('currentState')['events']);
        $this->assertContains('ConditionWaitSatisfied', $this->eventTypes($before->runId()));
        $this->assertContains('ConditionWaitTimedOut', $this->eventTypes($after->runId()));
    }

    public function testRecordedTimedOutFrom2290ReplaysUnchangedOnTheWorker(): void
    {
        $fixture = $this->history('2.29.0-in-flight-standalone');
        [$workflow, $imported] = $this->importInFlight($fixture, 'g230-replay-2290-timed-out');

        $this->assertSame(['approve', 'condition-timed-out'], $workflow->query('currentState')['events']);
        $this->finish($workflow);

        $this->assertSame([
            'approved' => false,
            'approved_state' => true,
            'events' => ['approve', 'condition-timed-out'],
        ], $workflow->refresh()
            ->output());
        $this->assertRecordedPrefixUnchanged($workflow->runId(), $imported);
    }

    public function testRecordedSatisfiedSelectFrom2290ReplaysUnchangedOnTheWorker(): void
    {
        // 2.29.0 evaluated a select() condition before its fired timeout, so an
        // update accepted after the deadline satisfied it. Unresolved, that
        // order now times out; recorded, it stays satisfied.
        $fixture = $this->history('2.29.0-in-flight-select-late');
        [$workflow, $imported] = $this->importInFlight($fixture, 'g230-replay-2290-select');

        $this->assertSame(['approve', 'condition-satisfied'], $workflow->query('currentState')['events']);
        $this->finish($workflow);

        $this->assertSame([
            'approved' => true,
            'approved_state' => true,
            'events' => ['approve', 'condition-satisfied'],
        ], $workflow->refresh()
            ->output());
        $this->assertRecordedPrefixUnchanged($workflow->runId(), $imported);
    }

    public function testRecordedTimedOutFrom2290ReplaysUnchangedThroughTheFiberRunner(): void
    {
        $fixture = $this->history('2.29.0-completed-standalone');

        $step = WorkflowFiberRunner::forClass(
            $fixture['workflow_type'],
            $fixture['output']['workflow_id'],
            $fixture['output']['run_id'],
            $fixture['arguments'],
            'avro',
            $fixture['history'],
        )->step();

        $this->assertTrue($step->completed);
        $this->assertSame($fixture['output'], $step->result);
    }

    private function startWaiting(string $class, string $instanceId, mixed ...$arguments): WorkflowStub
    {
        $this->at('12:00:00');
        $workflow = WorkflowStub::make($class, $instanceId);
        $workflow->start(...$arguments);
        $this->at('12:00:01');
        $this->runReady($workflow->runId(), TaskType::Workflow);
        $this->assertSame('waiting', $workflow->refresh()->status());

        return $workflow;
    }

    private function finish(WorkflowStub $workflow): void
    {
        $this->at('12:01:00');
        $this->assertTrue($workflow->submitUpdate('finish')->accepted());
        $this->drainWorkflowTasks($workflow->runId());
        $this->assertTrue($workflow->refresh()->completed());
    }

    /**
     * Load a 2.29.0 in-flight history, as stored, into a new run that keeps
     * its own start events, and check the query replayer reads the recorded
     * outcome.
     *
     * @param array<string, mixed> $fixture
     * @return array{0: WorkflowStub, 1: list<array{event_type: string, payload: array<string, mixed>}>}
     */
    private function importInFlight(array $fixture, string $instanceId): array
    {
        $this->at('12:00:00');
        $workflow = WorkflowStub::make($fixture['workflow_type'], $instanceId);
        $workflow->start(...$fixture['arguments']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->update([
                'status' => TaskStatus::Completed->value,
            ]);

        $imported = [];
        foreach ($fixture['history'] as $event) {
            if (in_array($event['event_type'], ['StartAccepted', 'WorkflowStarted'], true)) {
                continue;
            }

            WorkflowHistoryEvent::query()->create([
                'id' => $event['id'],
                'workflow_run_id' => $run->id,
                'sequence' => $event['sequence'],
                'event_type' => $event['event_type'],
                'payload' => $event['payload'],
                'recorded_at' => Carbon::parse($event['recorded_at']),
            ]);
            $imported[] = [
                'event_type' => $event['event_type'],
                'payload' => $event['payload'],
            ];
        }
        $run->forceFill([
            'status' => 'waiting',
            'last_history_sequence' => end($fixture['history'])['sequence'],
        ])->save();
        $this->at('12:00:30');

        $state = (new QueryStateReplayer())->replay($run->fresh(['historyEvents']));
        $this->assertNotNull($state);

        return [$workflow->refresh(), $imported];
    }

    /**
     * @param list<array{event_type: string, payload: array<string, mixed>}> $imported
     */
    private function assertRecordedPrefixUnchanged(string $runId, array $imported): void
    {
        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->reject(static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [HistoryEventType::StartAccepted, HistoryEventType::WorkflowStarted],
                true,
            ))
            ->values();

        foreach ($imported as $index => $expected) {
            $this->assertSame($expected['event_type'], $events[$index]->event_type->value);
            $this->assertSame($expected['payload'], $events[$index]->payload);
        }

        $resolutions = $events->filter(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [HistoryEventType::ConditionWaitSatisfied, HistoryEventType::ConditionWaitTimedOut],
                true,
            ) && ($event->payload['sequence'] ?? null) === 1
        );
        $this->assertCount(1, $resolutions, 'The recorded wait outcome must not be recorded again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $name): array
    {
        return json_decode(
            (string) file_get_contents(self::HISTORY_DIR . "/{$name}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-11-18 {$time}"));
    }

    /**
     * @return list<string>
     */
    private function eventTypes(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all();
    }

    private function drainWorkflowTasks(string $runId): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $ready = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->exists();

            if (! $ready) {
                return;
            }

            $this->runReady($runId, TaskType::Workflow);
        }
    }

    private function runReady(string $runId, TaskType $type): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $job = $type === TaskType::Workflow ? new RunWorkflowTask($task->id) : new RunTimerTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
