<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\child;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ChildWorkflowOptions;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeChildCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            child(
                TestLongRunningChildWorkflow::class,
                new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancel),
            );
        } finally {
            $greeting = activity(TestGreetingActivity::class, 'cleanup');
            upsertMemo([
                'cleanup' => $greeting,
            ]);
        }
    }
}
