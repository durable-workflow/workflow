<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-generator-workflow')]
final class TestGeneratorWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(string $name): Generator
    {
        $this->stage = 'running';

        $greeting = yield activity(TestGreetingActivity::class, $name);

        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    /**
     * @return array{stage: string}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
        ];
    }
}
