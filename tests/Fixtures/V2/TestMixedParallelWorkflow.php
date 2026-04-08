<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-mixed-parallel-workflow')]
final class TestMixedParallelWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function execute(string $name, int $seconds): Generator
    {
        $this->stage = 'waiting-for-mixed-group';

        $results = yield all([
            activity(TestGreetingActivity::class, $name),
            child(TestTimerWorkflow::class, $seconds),
        ]);

        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'results' => $results,
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
