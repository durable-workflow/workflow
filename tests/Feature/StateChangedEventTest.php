<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Events\StateChanged;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowPendingStatus;

final class StateChangedEventTest extends TestCase
{
    public function testEventDispatchedAfterStoredWorkflowStatusTransition(): void
    {
        $storedWorkflow = StoredWorkflow::create([
            'class' => TestWorkflow::class,
        ]);
        $storedWorkflow = StoredWorkflow::findOrFail($storedWorkflow->id);

        Event::fake([StateChanged::class]);

        $initialState = $storedWorkflow->status;
        $storedWorkflow->status->transitionTo(WorkflowPendingStatus::class);

        Event::assertDispatched(StateChanged::class, static function (StateChanged $event) use (
            $storedWorkflow,
            $initialState
        ) {
            return $event->initialState === $initialState
                && $event->initialState instanceof WorkflowCreatedStatus
                && $event->finalState instanceof WorkflowPendingStatus
                && $event->model->is($storedWorkflow)
                && $event->field === 'status';
        });
    }
}
