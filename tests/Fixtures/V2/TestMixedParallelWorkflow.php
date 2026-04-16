<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-mixed-parallel-workflow')]
final class TestMixedParallelWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(string $name, int $seconds): array
    {
        $this->stage = 'waiting-for-mixed-group';

        $results = all([
            fn () => activity(TestGreetingActivity::class, $name),
            fn () => child(TestTimerWorkflow::class, $seconds),
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
