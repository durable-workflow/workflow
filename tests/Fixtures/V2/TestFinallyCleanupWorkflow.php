<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use function Workflow\V2\activity;
use Workflow\V2\Support\ActivityOptions;

use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestFinallyCleanupWorkflow extends Workflow
{
    public function handle(bool $fail, int $wait = 0): string
    {
        try {
            if ($wait > 0) {
                timer($wait);
            }
            $result = activity(TestFinallyWorkActivity::class, new ActivityOptions(maxAttempts: 1), $fail);
        } catch (RuntimeException) {
            $result = 'handled';
        } finally {
            $cleanup = activity(TestGreetingActivity::class, 'cleanup');
            timer(1);
            upsertMemo([
                'cleanup' => $cleanup,
            ]);
        }

        return $result;
    }
}
