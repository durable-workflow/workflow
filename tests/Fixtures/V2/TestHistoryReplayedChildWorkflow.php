<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-history-child-replay-workflow')]
final class TestHistoryReplayedChildWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var array<string, mixed>
     */
    private array $childResult = [];

    public function execute(string $name): array
    {
        $this->stage = 'waiting-for-child';
        $this->childResult = child(TestChildGreetingWorkflow::class, $name);
        $this->stage = 'completed';

        return $this->currentState();
    }

    /**
     * @return array{stage: string, child: array<string, mixed>}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'child' => $this->childResult,
        ];
    }
}
