<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\ChildStartHistory;
use Workflow\V2\Workflow;

/** Exercise the recorded receipt parser without issuing live child commands or database queries. */
final class TestChildAwaitReceiptReplayWorkflow extends Workflow
{
    public function handle(array $identity, array $recorded): array
    {
        $child = StartedChildWorkflow::fromArray($identity);
        $parent = new WorkflowRun();
        $parent->setRawAttributes([
            'id' => $child->parentRunId,
            'namespace' => $child->namespace,
            'workflow_instance_id' => $child->parentInstanceId,
        ]);
        $event = new WorkflowHistoryEvent([
            'workflow_run_id' => $child->parentRunId,
            'event_type' => HistoryEventType::ChildWorkflowAwaited,
            'payload' => $recorded,
        ]);
        $parent->setRelation('historyEvents', collect([$event]));
        return [
            'accepted' => ChildStartHistory::awaitedEvent($parent, $child, 2) === $event,
        ];
    }
}
