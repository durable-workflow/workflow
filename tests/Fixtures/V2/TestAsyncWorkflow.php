<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use AssertionError;
use Illuminate\Contracts\Foundation\Application;
use function Workflow\V2\activity;
use function Workflow\V2\async;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-async-workflow')]
final class TestAsyncWorkflow extends Workflow
{
    public function execute(string $name): array
    {
        $asyncResult = async(static function (Application $app) use ($name): array {
            if (! $app->runningInConsole()) {
                throw new AssertionError('Test workflows must run in console.');
            }

            return [
                'greeting' => activity(TestGreetingActivity::class, $name),
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
