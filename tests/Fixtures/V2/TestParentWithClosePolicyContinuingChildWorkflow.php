<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\child;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Workflow;

/**
 * Starts a child workflow that uses continueAsNew() with a configurable parent-close
 * policy. Used to test that continue-as-new does not trigger the parent-close policy
 * and that the policy survives across the child's run chain.
 */
#[Type('test-parent-close-policy-continuing-child')]
final class TestParentWithClosePolicyContinuingChildWorkflow extends Workflow
{
    public function handle(string $policy = 'request_cancel', int $childMax = 1): array
    {
        $options = new ChildWorkflowOptions(
            parentClosePolicy: ParentClosePolicy::from($policy),
        );

        $childResult = child(TestContinueAsNewWorkflow::class, $options, 0, $childMax);

        return [
            'child' => $childResult,
            'parent_workflow_id' => $this->workflowId(),
        ];
    }
}
