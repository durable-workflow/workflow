<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\cancellationShield;
use function Workflow\V2\select;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeSelectionCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            $selected = select([
                'work' => static fn () => activity(TestFinallyWorkActivity::class, false),
                'deadline' => static fn () => timer(0),
            ]);
            $selected->handles['work']->await();
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
