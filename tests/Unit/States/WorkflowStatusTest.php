<?php

declare(strict_types=1);

namespace Tests\Unit\States;

use Tests\NonDatabaseTestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\States\WorkflowWaitingStatus;

final class WorkflowStatusTest extends NonDatabaseTestCase
{
    public function testConfig(): void
    {
        $config = StoredWorkflow::make()->getStates()->first()->all();
        $this->assertSame([
            WorkflowCompletedStatus::$name,
            WorkflowContinuedStatus::$name,
            WorkflowCreatedStatus::$name,
            WorkflowFailedStatus::$name,
            WorkflowPendingStatus::$name,
            WorkflowRunningStatus::$name,
            WorkflowWaitingStatus::$name,
        ], $config);
    }
}
