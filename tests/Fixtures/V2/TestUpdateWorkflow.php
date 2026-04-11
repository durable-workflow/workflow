<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-update-workflow')]
#[Signal('name-provided', [
    [
        'name' => 'name',
        'type' => 'string',
    ],
])]
final class TestUpdateWorkflow extends Workflow
{
    private string $stage = 'booting';

    private bool $approved = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->stage = 'waiting-for-name';
        $this->events[] = 'started';

        $name = awaitSignal('name-provided');

        $this->stage = 'completed';
        $this->events[] = sprintf('signal:%s', $name);

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

    #[UpdateMethod]
    public function approve(bool $approved, string $source = 'manual'): array
    {
        $this->approved = $approved;
        $this->events[] = sprintf('approved:%s:%s', $approved ? 'yes' : 'no', $source);

        return [
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[UpdateMethod]
    public function explode(string $message): never
    {
        throw new RuntimeException($message);
    }
}
