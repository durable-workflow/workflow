<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestMemoUpsertWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\WorkflowStub;

final class V2MemoUpsertTest extends TestCase
{
    public function testWorkflowCanUpsertMemo(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'memo-test-1',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $memo = $workflow->memo();

        $this->assertSame('Taylor', $memo['customer_name']);
        $this->assertSame('completed', $memo['status']);
        $this->assertSame('Hello, Taylor!', $memo['result_summary']);
        $this->assertSame(['greeting', 'test'], $memo['tags']);
    }

    public function testMemoUpsertRecordsDurableHistoryEvents(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-2');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get();

        $upsertEvents = $events->filter(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::MemoUpserted
        );

        $this->assertSame(2, $upsertEvents->count());

        $firstUpsert = $upsertEvents->first();
        $this->assertSameJsonObject([
            'customer_name' => 'Taylor',
            'status' => 'processing',
            'tags' => ['greeting', 'test'],
        ], MemoPayload::decodeEntries($firstUpsert->payload['entries']));

        $secondUpsert = $upsertEvents->last();
        $this->assertSameJsonObject([
            'result_summary' => 'Hello, Taylor!',
            'status' => 'completed',
        ], MemoPayload::decodeEntries($secondUpsert->payload['entries']));
        $this->assertSameJsonObject([
            'customer_name' => 'Taylor',
            'result_summary' => 'Hello, Taylor!',
            'status' => 'completed',
            'tags' => ['greeting', 'test'],
        ], MemoPayload::decodeEntries($secondUpsert->payload['merged']));
    }

    public function testExecutorBlocksReplayWhenRecordedMemoEntriesDrift(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-replay-entry-drift');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::MemoUpserted, [
            'sequence' => 1,
            'entries' => MemoPayload::envelope([
                'customer_name' => 'Taylor',
                'status' => 'changed-after-deployment',
                'tags' => ['greeting', 'test'],
            ]),
            'merged' => MemoPayload::envelope([
                'customer_name' => 'Taylor',
                'status' => 'changed-after-deployment',
                'tags' => ['greeting', 'test'],
            ]),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);

        $task->refresh();

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame(
            'memo upsert with matching entries',
            $task->payload['replay_blocked_expected_history_shape'] ?? null
        );
        $this->assertSame(['MemoUpserted'], $task->payload['replay_blocked_recorded_event_types'] ?? null);
        $this->assertStringContainsString(
            'Recorded memo entries do not match the current yielded entries.',
            (string) $task->last_error,
        );
    }

    public function testQueryReplayRejectsRecordedMemoEntriesThatNoLongerMatch(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-query-replay-entry-drift');
        $workflow->start('Taylor');

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::MemoUpserted->value)
            ->orderBy('sequence')
            ->firstOrFail();
        $payload = $event->payload;
        $entries = MemoPayload::decodeEntries($payload['entries']);
        $entries['status'] = 'changed-after-deployment';
        $payload['entries'] = MemoPayload::envelope($entries);
        $event->forceFill([
            'payload' => $payload,
        ])->save();

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('Recorded memo entries do not match the current yielded entries.');

        (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($workflow->runId()));
    }

    public function testMemoMergesAcrossMultipleUpserts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-3');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();

        $this->assertSameJsonObject([
            'customer_name' => 'Taylor',
            'result_summary' => 'Hello, Taylor!',
            'status' => 'completed',
            'tags' => ['greeting', 'test'],
        ], $run->typedMemos());
    }

    public function testHistoryEventSequenceIncludesMemoUpserts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-4');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::MemoUpserted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::MemoUpserted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], $eventTypes);
    }

    public function testNullValueRemovesMemoKey(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-5');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();

        // The workflow does not set any null keys, so all keys should be present
        $runMemo = $run->typedMemos();
        $this->assertArrayHasKey('customer_name', $runMemo);
        $this->assertArrayHasKey('status', $runMemo);
        $this->assertArrayHasKey('result_summary', $runMemo);
        $this->assertArrayHasKey('tags', $runMemo);
    }

    public function testMemoAccessibleOnWorkflowStub(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestMemoUpsertWorkflow::class, 'memo-test-6');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $memo = $workflow->memo();

        $this->assertIsArray($memo);
        $this->assertSame('Taylor', $memo['customer_name']);
        $this->assertSame('completed', $memo['status']);
    }

    public function testMemoUpsertCallRejectsEmptyArray(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least one entry');

        new \Workflow\V2\Support\UpsertMemoCall([]);
    }

    public function testMemoUpsertCallRejectsInvalidKeys(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Workflow v2 memo keys must match %s.', MemoPayload::KEY_PATTERN));

        new \Workflow\V2\Support\UpsertMemoCall([
            '' => 'value',
        ]);
    }

    public function testMemoUpsertCallAcceptsNestedStructures(): void
    {
        $call = new \Workflow\V2\Support\UpsertMemoCall([
            'order' => [
                'id' => 123,
                'items' => ['widget', 'gadget'],
            ],
        ]);

        $this->assertSame([
            'order' => [
                'id' => 123,
                'items' => ['widget', 'gadget'],
            ],
        ], $call->entries);
    }
}
