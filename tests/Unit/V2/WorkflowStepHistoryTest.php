<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestChildGreetingWorkflow;
use Tests\Fixtures\V2\TestMixedEntryActivity;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowStepHistory;

final class WorkflowStepHistoryTest extends TestCase
{
    public function testActivityTypeDetailAcceptsCanonicalTypeAliasForYieldedClass(): void
    {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ActivityScheduled, [
                'sequence' => 1,
                'activity_type' => 'test-mixed-entry-activity',
                'activity_class' => TestMixedEntryActivity::class,
            ]),
        ]);

        WorkflowStepHistory::assertCompatible($run, 1, WorkflowStepHistory::ACTIVITY, [
            'activity_type' => TestMixedEntryActivity::class,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testActivityTypeDetailRejectsMutatedTypeEvenWhenClassFallbackMatches(): void
    {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ActivityScheduled, [
                'sequence' => 1,
                'activity_type' => 'changed-activity-type',
                'activity_class' => TestMixedEntryActivity::class,
            ]),
        ]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('Recorded activity_type [changed-activity-type]');

        WorkflowStepHistory::assertCompatible($run, 1, WorkflowStepHistory::ACTIVITY, [
            'activity_type' => TestMixedEntryActivity::class,
        ]);
    }

    public function testChildWorkflowTypeDetailAcceptsCanonicalTypeAliasForYieldedClass(): void
    {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ChildWorkflowScheduled, [
                'sequence' => 2,
                'child_workflow_type' => 'test-child-greeting-workflow',
                'child_workflow_class' => TestChildGreetingWorkflow::class,
            ]),
        ]);

        WorkflowStepHistory::assertCompatible($run, 2, WorkflowStepHistory::CHILD_WORKFLOW, [
            'child_workflow_type' => TestChildGreetingWorkflow::class,
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private function runWithHistoryEvents(array $events): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', new EloquentCollection($events));

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function historyEvent(HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'sequence' => $payload['sequence'] ?? 1,
            'event_type' => $type->value,
            'payload' => $payload,
        ]);

        return $event;
    }
}
