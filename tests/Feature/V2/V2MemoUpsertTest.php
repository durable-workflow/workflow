<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestMemoUpsertWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
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
        ], $firstUpsert->payload['entries']);

        $secondUpsert = $upsertEvents->last();
        $this->assertSameJsonObject([
            'result_summary' => 'Hello, Taylor!',
            'status' => 'completed',
        ], $secondUpsert->payload['entries']);
        $this->assertSameJsonObject([
            'customer_name' => 'Taylor',
            'result_summary' => 'Hello, Taylor!',
            'status' => 'completed',
            'tags' => ['greeting', 'test'],
        ], $secondUpsert->payload['merged']);
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
        ], $run->memo);
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
        $this->assertArrayHasKey('customer_name', $run->memo);
        $this->assertArrayHasKey('status', $run->memo);
        $this->assertArrayHasKey('result_summary', $run->memo);
        $this->assertArrayHasKey('tags', $run->memo);
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
        $this->expectExceptionMessage('non-empty strings up to 64 characters');

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
