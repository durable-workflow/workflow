<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use Workflow\Workflow;

final class TestFailedActivityRedispatchWorkflow extends Workflow
{
    public function execute(string $directory)
    {
        try {
            return yield activity(TestFailedActivityRedispatchActivity::class, $directory);
        } catch (Throwable $throwable) {
            return 'caught: ' . $throwable->getMessage();
        }
    }
}
