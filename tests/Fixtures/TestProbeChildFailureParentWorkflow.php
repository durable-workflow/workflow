<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use function Workflow\all;
use function Workflow\child;
use Workflow\Workflow;

final class TestProbeChildFailureParentWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestProbeChildFailureParentStepActivity::class);

            $this->addCompensation(static fn () => activity(TestProbeChildFailureCompensationActivity::class));

            yield all([
                child(TestProbeChildFailureWorkflow::class, 'child-1'),
                child(TestProbeChildFailureWorkflow::class, 'child-2'),
                child(TestProbeChildFailureWorkflow::class, 'child-3'),
            ]);

            return 'unexpected-success';
        } catch (Throwable $throwable) {
            yield from $this->compensate();

            return 'caught: ' . $throwable->getMessage();
        }
    }
}
