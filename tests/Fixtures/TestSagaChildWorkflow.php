<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Workflow;
use function Workflow\{activity, all, child};

class TestSagaChildWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestActivity::class);
            $this->addCompensation(static fn () => activity(TestUndoActivity::class));

            $children = [
                child(TestChildExceptionThrowingWorkflow::class),
                child(TestChildExceptionThrowingWorkflow::class),
                child(TestChildExceptionThrowingWorkflow::class),
            ];

            yield all($children);

            return 'success';
        } catch (\Throwable $th) {
            yield from $this->compensate();

            return 'compensated';
        }
    }
}
