<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\child;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Workflow;

/**
 * Starts a long-running child workflow with a configurable parent-close policy,
 * then blocks waiting for the child to complete. Used to test policy enforcement
 * when the parent is cancelled, terminated, or timed out externally.
 */
#[Type('test-parent-close-policy')]
final class TestParentWithClosePolicyWorkflow extends Workflow
{
    public function handle(string $policy = 'abandon'): array
    {
        $options = new ChildWorkflowOptions(
            parentClosePolicy: ParentClosePolicy::from($policy),
        );

        $childResult = child(TestLongRunningChildWorkflow::class, $options);

        return [
            'child' => $childResult,
            'parent_workflow_id' => $this->workflowId(),
        ];
    }
}
