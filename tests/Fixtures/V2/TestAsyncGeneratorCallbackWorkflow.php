<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use function Workflow\V2\activity;
use function Workflow\V2\async;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-async-generator-callback-workflow')]
final class TestAsyncGeneratorCallbackWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'async' => async(static function () use ($name): Generator {
                $greeting = yield activity(TestGreetingActivity::class, $name);

                return [
                    'greeting' => $greeting,
                    'compatibility' => 'generator-callback',
                ];
            }),
        ];
    }
}
