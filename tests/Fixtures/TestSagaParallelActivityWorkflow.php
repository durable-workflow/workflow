<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Workflow\activity;
use Workflow\ActivityStub;
use Workflow\Workflow;

class TestSagaParallelActivityWorkflow extends Workflow
{
    public function execute()
    {
        try {
            yield activity(TestActivity::class);
            $this->addCompensation(static fn () => activity(TestUndoActivity::class));

            yield ActivityStub::all([
                activity(TestSagaActivity::class),
                activity(TestSagaActivity::class),
                activity(TestSagaActivity::class),
            ]);

            return 'success';
        } catch (\Throwable $th) {
            yield from $this->compensate();
        }

        return 'compensated';
    }
}
