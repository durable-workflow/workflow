<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use function Workflow\all;
use Workflow\Workflow;

final class TestParallelCaughtExceptionWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield all([
                activity(TestSingleTryExceptionActivity::class, true),
                activity(TestSingleTryExceptionActivity::class, true),
            ]);
        } catch (Throwable) {
            return 'handled';
        }

        return 'unhandled';
    }
}
