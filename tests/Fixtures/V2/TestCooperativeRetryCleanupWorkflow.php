<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Support\ActivityOptions;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCooperativeRetryCleanupWorkflow extends Workflow
{
    public function handle(bool $failCleanup = false): void
    {
        try {
            timer(3600);
        } finally {
            if ($failCleanup) {
                activity(TestFinallyWorkActivity::class, new ActivityOptions(maxAttempts: 1), true);
            } else {
                $result = activity(TestRetryActivity::class, 'cleanup');
                upsertMemo([
                    'cleanup' => $result['message'],
                ]);
            }
        }
    }
}
