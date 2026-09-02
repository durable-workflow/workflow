<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-child-workflow')]
final class TestParallelChildWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(int $firstSeconds, int $secondSeconds): array
    {
        $this->stage = 'waiting-for-children';

        $children = all([
            static fn () => child(TestTimerWorkflow::class, $firstSeconds),
            static fn () => child(TestTimerWorkflow::class, $secondSeconds),
        ]);

        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'children' => $children,
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
