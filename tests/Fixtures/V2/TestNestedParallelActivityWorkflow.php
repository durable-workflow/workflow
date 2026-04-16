<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-nested-parallel-activity-workflow')]
final class TestNestedParallelActivityWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(string $firstName, string $secondName, string $thirdName): array
    {
        $this->stage = 'waiting-for-activities';

        $results = all([
            fn () => activity(TestGreetingActivity::class, $firstName),
            fn () => all([
                fn () => activity(TestGreetingActivity::class, $secondName),
                fn () => activity(TestGreetingActivity::class, $thirdName),
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
