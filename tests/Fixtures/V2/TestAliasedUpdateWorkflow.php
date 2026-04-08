<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-aliased-update-workflow')]
#[Signal('name-provided')]
final class TestAliasedUpdateWorkflow extends Workflow
{
    private string $stage = 'booting';

    private bool $approved = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function execute(): Generator
    {
        $this->stage = 'waiting-for-name';
        $this->events[] = 'started';

        yield awaitSignal('name-provided');

        $this->stage = 'completed';
        $this->events[] = 'signal:received';

        return [
            'approved' => $this->approved,
            'events' => $this->events,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[UpdateMethod('mark-approved')]
    public function applyApproval(bool $approved, string $source = 'manual'): array
    {
        $this->approved = $approved;
        $this->events[] = sprintf('approved:%s:%s', $approved ? 'yes' : 'no', $source);

        return [
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }
}
