<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\child;
use Workflow\V2\Enums\ParentClosePolicy;
use function Workflow\V2\select;
use Workflow\V2\Support\ChildWorkflowOptions;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestParentCloseCooperativeWorkflow extends Workflow
{
    public function handle(): void
    {
        // The timer wins. Both child handles stay active when the parent closes.
        select([
            'first' => static fn () => child(
                TestChildPolicyCleanupWorkflow::class,
                new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancellation),
            ),
            'second' => static fn () => child(
                TestChildPolicyCleanupWorkflow::class,
                new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancellation),
            ),
            'ready' => static fn () => timer(1),
        ]);
    }
}
