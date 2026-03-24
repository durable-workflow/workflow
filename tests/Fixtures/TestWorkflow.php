<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use AssertionError;
use Illuminate\Contracts\Foundation\Application;
use Workflow\QueryMethod;
use Workflow\SignalMethod;
use Workflow\Webhook;
use Workflow\Workflow;
use function Workflow\{activity, await, sideEffect};

#[Webhook]
class TestWorkflow extends Workflow
{
    public $connection = 'redis';

    public $queue = 'default';

    private bool $canceled = false;

    #[SignalMethod]
    #[Webhook]
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

        yield await(fn (): bool => $this->canceled);

        $result = yield activity(TestActivity::class);

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
