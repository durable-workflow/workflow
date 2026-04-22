<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-start-boundary-receiver-workflow')]
#[Signal('name-provided')]
final class TestStartBoundaryReceiverWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->stage = 'initialized';
        $this->events[] = 'initialized';

        $name = signal('name-provided');

        $this->stage = 'completed';
        $this->events[] = sprintf('signal:%s:%s', $name, $this->stage);

        return [
            'stage' => $this->stage,
            'events' => $this->events,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved, string $source = 'manual'): array
    {
        $this->events[] = sprintf('update:%s:%s:%s', $approved ? 'yes' : 'no', $source, $this->stage);

        return [
            'stage' => $this->stage,
            'events' => $this->events,
        ];
    }
}
