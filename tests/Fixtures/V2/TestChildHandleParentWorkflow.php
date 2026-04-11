<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-child-handle-parent-workflow')]
final class TestChildHandleParentWorkflow extends Workflow
{
    public function execute(): array
    {
        $child = child(TestChildHandleChildWorkflow::class);

        return [
            'parent_workflow_id' => $this->workflowId(),
            'parent_run_id' => $this->runId(),
            'child' => $child,
        ];
    }

    #[QueryMethod('current-child-handle')]
    public function currentChildHandle(): ?array
    {
        $handle = $this->child();

        if ($handle === null) {
            return null;
        }

        return [
            'instance_id' => $handle->instanceId(),
            'run_id' => $handle->runId(),
            'call_id' => $handle->callId(),
        ];
    }

    #[UpdateMethod('approve-child')]
    public function approveChild(string $approvedBy): void
    {
        $this->child()?->signal('approved-by', $approvedBy);
    }
}
