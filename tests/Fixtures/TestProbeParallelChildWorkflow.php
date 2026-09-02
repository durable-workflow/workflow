<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\all;
use function Workflow\child;
use Workflow\Workflow;

final class TestProbeParallelChildWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield all([
                child(TestProbeChildFailureWorkflow::class, 'child-1'),
                child(TestProbeChildFailureWorkflow::class, 'child-2'),
            ]);

            return 'unexpected-success';
        } catch (Throwable) {
            return 'caught';
        }
    }
}
