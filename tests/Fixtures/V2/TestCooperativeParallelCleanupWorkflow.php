<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\cancellationShield;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeParallelCleanupWorkflow extends Workflow
{
    public function handle(bool $waitForTimer = true): void
    {
        try {
            $second = $waitForTimer
                ? static fn () => timer(3600)
                : static fn () => activity(TestFinallyWorkActivity::class, false);
            all([
                static fn () => activity(TestFinallyWorkActivity::class, false),
                $second,
            ]);
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
