<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\parallel;
use function Workflow\V2\startActivity;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

#[Type('test-fiber-parallel-workflow')]
final class TestFiberParallelWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(string $firstName, string $secondName, string $thirdName): array
    {
        $this->stage = 'waiting-for-parallel-work';

        $results = all([
            startActivity(TestGreetingActivity::class, $firstName),
            parallel([
                startActivity(TestGreetingActivity::class, $secondName),
                startChild(TestChildGreetingWorkflow::class, $thirdName),
            ]),
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
