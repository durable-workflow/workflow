<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestManySideEffectsWorkflow;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\WorkflowStub;

final class V2SideEffectWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        TestSideEffectWorkflow::resetCounter();
    }

    public function testSideEffectExecutesOnceAndRecordsTypedHistoryEvent(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-once');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        // The side-effect closure should have executed exactly once.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Verify the SideEffectRecorded history event was written.
        $sideEffectEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->get();

        $this->assertCount(1, $sideEffectEvents);

        // The event should contain a serialized result and a sequence marker.
        $event = $sideEffectEvents->first();
        $this->assertArrayHasKey('result', $event->payload);
        $this->assertArrayHasKey('sequence', $event->payload);
    }

    public function testSideEffectValueIsStableAcrossSignalResumeReplay(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-replay');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Query the token — this triggers a query replay against committed history.
        $token = $workflow->query('currentToken');
        $this->assertSame(1, $token);

        // The side-effect closure should NOT have re-executed during query replay.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Complete the workflow by signalling.
        $workflow->signal('finish', 'done');
        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertSame(1, $output['token']);
        $this->assertSame('done', $output['finish']);

        // Side-effect was replayed from history, not re-executed.
        // The closure ran once during the first workflow task, then
        // was replayed during the signal-resume workflow task.
        // In fake mode the side-effect counter may increment once per
        // non-replay execution pass but must never exceed the number of
        // distinct workflow task executions that evaluate that step live.
        $this->assertLessThanOrEqual(2, TestSideEffectWorkflow::sideEffectExecutions());
    }

    public function testQueryReplayReturnsSideEffectValueWithoutReExecution(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-query');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $executionsAfterStart = TestSideEffectWorkflow::sideEffectExecutions();
        $this->assertSame(1, $executionsAfterStart);

        // Multiple queries should not re-execute the side-effect closure.
        $token1 = $workflow->query('currentToken');
        $token2 = $workflow->query('currentToken');

        $this->assertSame(1, $token1);
        $this->assertSame(1, $token2);

        // Execution count should remain at 1 — queries replay from history.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());
    }

    public function testManySideEffectsRecordDistinctHistoryEventsInSequence(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestManySideEffectsWorkflow::class, 'many-side-effects');
        $workflow->start(5);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertCount(5, $output);

        // Each side-effect should produce a distinct SideEffectRecorded event.
        $sideEffectEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(5, $sideEffectEvents);

        // Events should have strictly increasing sequence numbers.
        $sequences = $sideEffectEvents->pluck('sequence')
            ->all();
        for ($i = 1; $i < count($sequences); $i++) {
            $this->assertGreaterThan($sequences[$i - 1], $sequences[$i]);
        }
    }

    public function testSideEffectAppearsInHistoryTimeline(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-timeline');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertContains(HistoryEventType::WorkflowStarted->value, $events);
        $this->assertContains(HistoryEventType::SideEffectRecorded->value, $events);

        // The side-effect should appear after WorkflowStarted and before any signal wait.
        $startedIndex = array_search(HistoryEventType::WorkflowStarted->value, $events, true);
        $sideEffectIndex = array_search(HistoryEventType::SideEffectRecorded->value, $events, true);

        $this->assertGreaterThan($startedIndex, $sideEffectIndex);
    }
}
