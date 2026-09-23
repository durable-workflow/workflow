<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\cancellationShield;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeNormalCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            activity(TestFinallyWorkActivity::class, false);
        } finally {
            cancellationShield(static function (): void {
                $greeting = activity(TestGreetingActivity::class, 'cleanup');
                upsertMemo([
                    'cleanup' => $greeting,
                ]);
            });
        }
    }
}
