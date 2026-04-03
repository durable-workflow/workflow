<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use Workflow\Workflow;
use function Workflow\activity;

final class TestProbeBackToBackWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestProbeRetryActivity::class, 1);
        } catch (Throwable) {
        }

        try {
            yield activity(TestProbeRetryActivity::class, 2);
        } catch (Throwable $throwable) {
            return 'caught second: ' . $throwable->getMessage();
        }

        return 'unexpected-success';
    }
}
