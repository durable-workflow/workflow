<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use AssertionError;
use Generator;
use Illuminate\Contracts\Foundation\Application;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\async;
use Workflow\V2\Workflow;

#[Type('test-async-workflow')]
final class TestAsyncWorkflow extends Workflow
{
    public function execute(string $name): Generator
    {
        $asyncResult = yield async(static function (Application $app) use ($name): Generator {
            if (! $app->runningInConsole()) {
                throw new AssertionError('Test workflows must run in console.');
            }

            return [
                'greeting' => yield activity(TestGreetingActivity::class, $name),
                'in_console' => $app->runningInConsole(),
            ];
        });

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'async' => $asyncResult,
        ];
    }
}
