<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestContinueAsNewMetadataWorkflow;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2ContinueAsNewMetadataTest extends TestCase
{
    public function testSearchAttributesCarryForwardThroughContinueAsNew(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, run!');

        $workflow = WorkflowStub::make(TestContinueAsNewMetadataWorkflow::class, 'meta-continue-1');
        $workflow->start(
            0,
            1,
            StartOptions::rejectDuplicate()->withSearchAttributes([
                'env' => 'production',
                'team' => 'platform',
            ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'meta-continue-1')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);

        $firstRunAttrs = $runs[0]->typedSearchAttributes();
        $this->assertSame('production', $firstRunAttrs['env']);
        $this->assertSame('platform', $firstRunAttrs['team']);
        $this->assertSame('processing', $firstRunAttrs['status']);
        $this->assertSame('0', $firstRunAttrs['iteration']);

        $secondRunAttrs = $runs[1]->typedSearchAttributes();
        $this->assertSame('production', $secondRunAttrs['env']);
        $this->assertSame('platform', $secondRunAttrs['team']);
        $this->assertSame('processing', $secondRunAttrs['status']);
        $this->assertSame('1', $secondRunAttrs['iteration']);

        $finalAttrs = $workflow->searchAttributes();
        $this->assertSame('production', $finalAttrs['env']);
        $this->assertSame('platform', $finalAttrs['team']);
    }

    public function testMemoCarriesForwardThroughContinueAsNew(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, run!');

        $workflow = WorkflowStub::make(TestContinueAsNewMetadataWorkflow::class, 'meta-continue-2');
        $workflow->start(
            0,
            1,
            StartOptions::rejectDuplicate()->withMemo([
                'customer' => 'Taylor',
                'priority' => 'high',
            ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'meta-continue-2')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);

        $firstRunMemo = $runs[0]->typedMemos();
        $this->assertSame('Taylor', $firstRunMemo['customer']);
        $this->assertSame('high', $firstRunMemo['priority']);
        $this->assertSame('Hello, run!', $firstRunMemo['last_greeting']);
        $this->assertSame(0, $firstRunMemo['iteration']);

        $secondRunMemo = $runs[1]->typedMemos();
        $this->assertSame('Taylor', $secondRunMemo['customer']);
        $this->assertSame('high', $secondRunMemo['priority']);
        $this->assertSame('Hello, run!', $secondRunMemo['last_greeting']);
        $this->assertSame(1, $secondRunMemo['iteration']);

        $finalMemo = $workflow->memo();
        $this->assertSame('Taylor', $finalMemo['customer']);
        $this->assertSame('high', $finalMemo['priority']);
    }

    public function testMidRunUpsertsCarryForwardThroughContinueAsNew(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, run!');

        $workflow = WorkflowStub::make(TestContinueAsNewMetadataWorkflow::class, 'meta-continue-3');
        $workflow->start(0, 1);

        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'meta-continue-3')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);

        $firstRunAttrs = $runs[0]->typedSearchAttributes();
        $this->assertSame('0', $firstRunAttrs['iteration']);
        $this->assertSame('processing', $firstRunAttrs['status']);

        // The second run inherits the first run's upserts, then upserts its own
        $secondRunAttrs = $runs[1]->typedSearchAttributes();
        $this->assertSame('1', $secondRunAttrs['iteration']);
        $this->assertSame('processing', $secondRunAttrs['status']);

        $firstRunMemo = $runs[0]->typedMemos();
        $this->assertSame('Hello, run!', $firstRunMemo['last_greeting']);
        $this->assertSame(0, $firstRunMemo['iteration']);

        // The second run inherits memo from the first, then overwrites with its own upsert
        $secondRunMemo = $runs[1]->typedMemos();
        $this->assertSame('Hello, run!', $secondRunMemo['last_greeting']);
        $this->assertSame(1, $secondRunMemo['iteration']);
    }

    public function testStartMetadataSeedsTypedRowsAndContinueAsNewInheritsThem(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, run!');

        $workflow = WorkflowStub::make(TestContinueAsNewMetadataWorkflow::class, 'meta-continue-typed');
        $workflow->start(
            0,
            1,
            StartOptions::rejectDuplicate()
                ->withSearchAttributes([
                    'env' => 'production',
                    'team' => 'platform',
                ])
                ->withMemo([
                    'customer' => 'Taylor',
                    'priority' => 'high',
                ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'meta-continue-typed')
            ->orderBy('run_number')
            ->get()
            ->values();

        $this->assertCount(2, $runs);

        $firstRunSearchAttributes = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $runs[0]->id)
            ->get()
            ->keyBy('key');
        $secondRunSearchAttributes = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->get()
            ->keyBy('key');

        $this->assertSame('production', $firstRunSearchAttributes['env']->getValue());
        $this->assertSame(0, $firstRunSearchAttributes['env']->upserted_at_sequence);
        $this->assertFalse($firstRunSearchAttributes['env']->inherited_from_parent);
        $this->assertSame('platform', $firstRunSearchAttributes['team']->getValue());
        $this->assertSame(0, $firstRunSearchAttributes['team']->upserted_at_sequence);
        $this->assertFalse($firstRunSearchAttributes['team']->inherited_from_parent);

        $this->assertSame('production', $secondRunSearchAttributes['env']->getValue());
        $this->assertSame(1, $secondRunSearchAttributes['env']->upserted_at_sequence);
        $this->assertTrue($secondRunSearchAttributes['env']->inherited_from_parent);
        $this->assertSame('platform', $secondRunSearchAttributes['team']->getValue());
        $this->assertSame(1, $secondRunSearchAttributes['team']->upserted_at_sequence);
        $this->assertTrue($secondRunSearchAttributes['team']->inherited_from_parent);
        $this->assertSame('1', $secondRunSearchAttributes['iteration']->getValue());
        $this->assertFalse($secondRunSearchAttributes['iteration']->inherited_from_parent);

        $firstRunMemos = WorkflowMemo::query()
            ->where('workflow_run_id', $runs[0]->id)
            ->get()
            ->keyBy('key');
        $secondRunMemos = WorkflowMemo::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->get()
            ->keyBy('key');

        $this->assertSame('Taylor', $firstRunMemos['customer']->getValue());
        $this->assertSame(0, $firstRunMemos['customer']->upserted_at_sequence);
        $this->assertFalse($firstRunMemos['customer']->inherited_from_parent);
        $this->assertSame('high', $firstRunMemos['priority']->getValue());
        $this->assertSame(0, $firstRunMemos['priority']->upserted_at_sequence);
        $this->assertFalse($firstRunMemos['priority']->inherited_from_parent);

        $this->assertSame('Taylor', $secondRunMemos['customer']->getValue());
        $this->assertSame(1, $secondRunMemos['customer']->upserted_at_sequence);
        $this->assertTrue($secondRunMemos['customer']->inherited_from_parent);
        $this->assertSame('high', $secondRunMemos['priority']->getValue());
        $this->assertSame(1, $secondRunMemos['priority']->upserted_at_sequence);
        $this->assertTrue($secondRunMemos['priority']->inherited_from_parent);
        $this->assertSame(1, $secondRunMemos['iteration']->getValue());
        $this->assertFalse($secondRunMemos['iteration']->inherited_from_parent);
    }

    public function testContinueAsNewMetadataHistoryEventsAreRecordedPerRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, run!');

        $workflow = WorkflowStub::make(TestContinueAsNewMetadataWorkflow::class, 'meta-continue-4');
        $workflow->start(0, 1);

        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'meta-continue-4')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);

        $firstRunEventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[0]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::SearchAttributesUpserted->value,
            HistoryEventType::MemoUpserted->value,
            HistoryEventType::WorkflowContinuedAsNew->value,
        ], $firstRunEventTypes);

        $secondRunEventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        // The final run completes normally
        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::SearchAttributesUpserted->value,
            HistoryEventType::MemoUpserted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], $secondRunEventTypes);
    }
}
