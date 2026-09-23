<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Throwable;
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

final class TestHandleFailureThenThrowWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            activity(TestNonRetryableActivity::class);
        } catch (Throwable) {
            throw new RuntimeException('The workflow rejected the failed activity.');
        }

        return 'never';
    }
}
