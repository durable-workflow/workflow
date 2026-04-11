<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parent-waiting-on-continuing-child-workflow')]
final class TestParentWaitingOnContinuingChildWorkflow extends Workflow
{
    public function handle(int $count = 0, int $max = 1): array
    {
        $child = child(TestContinueAsNewWorkflow::class, $count, $max);

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

    #[QueryMethod('child-handles')]
    public function childHandles(): array
    {
        return array_map(
            static fn ($handle): array => [
                'instance_id' => $handle->instanceId(),
                'run_id' => $handle->runId(),
                'call_id' => $handle->callId(),
            ],
            $this->children(),
        );
    }
}
