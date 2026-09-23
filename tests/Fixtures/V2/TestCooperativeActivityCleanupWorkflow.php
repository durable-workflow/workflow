<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeActivityCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            activity(TestFinallyWorkActivity::class, false);
        } finally {
            $greeting = activity(TestGreetingActivity::class, 'cleanup');
            upsertMemo([
                'cleanup' => $greeting,
            ]);
        }
    }
}
