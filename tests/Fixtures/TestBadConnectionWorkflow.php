<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use AssertionError;
use Illuminate\Contracts\Foundation\Application;
use Workflow\QueryMethod;
use Workflow\SignalMethod;
use Workflow\Workflow;
use function Workflow\{activity, await, sideEffect};

class TestBadConnectionWorkflow extends Workflow
{
    public $connection = 'bad';

    public $queue = 'default';

    private bool $canceled = false;

    #[SignalMethod]
    public function cancel(): void
    {
        $this->canceled = true;
    }

    #[QueryMethod]
    public function isCanceled(): bool
    {
        return $this->canceled;
    }

    public function execute(Application $app, $shouldAssert = false)
    {
        if (! $app->runningInConsole()) {
            throw new AssertionError('Test workflows must run in console.');
        }

        if ($shouldAssert) {
            if (! (yield sideEffect(fn (): bool => ! $this->canceled))) {
                throw new AssertionError('Workflow should not be canceled before the first activity.');
            }
        }

        $otherResult = yield activity(TestOtherActivity::class, 'other');

        if ($shouldAssert) {
            if (! (yield sideEffect(fn (): bool => ! $this->canceled))) {
                throw new AssertionError('Workflow should not be canceled before awaiting the signal.');
            }
        }

        yield await(fn (): bool => $this->canceled);

        $result = yield activity(TestActivity::class);

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
