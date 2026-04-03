<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use Workflow\Workflow;

final class TestConsecutiveCaughtExceptionWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestSingleTryExceptionActivity::class, true);
        } catch (Throwable) {
            try {
                yield activity(TestSingleTryExceptionActivity::class, true);
            } catch (Throwable) {
                return 'handled';
            }
        }

        return 'unhandled';
    }
}
