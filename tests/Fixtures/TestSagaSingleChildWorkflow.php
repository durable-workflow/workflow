<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Workflow;
use function Workflow\{activity, child};

class TestSagaSingleChildWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestActivity::class);
            $this->addCompensation(static fn () => activity(TestUndoActivity::class));

            yield child(TestChildExceptionThrowingWorkflow::class);

            return 'success';
        } catch (\Throwable $th) {
            yield from $this->compensate();

            return 'compensated';
        }
    }
}
