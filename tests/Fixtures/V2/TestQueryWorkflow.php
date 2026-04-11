<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-query-workflow')]
#[Signal('name-provided')]
final class TestQueryWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->stage = 'waiting-for-name';
        $this->events[] = 'started';

        $name = awaitSignal('name-provided');

        $this->stage = 'waiting-for-timer';
        $this->events[] = sprintf('name:%s', $name);

        timer(60);

        $this->stage = 'completed';
        $this->events[] = 'timer-fired';

        return [
            'events' => $this->events,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentStage(): string
    {
        return $this->stage;
    }

    #[QueryMethod]
    public function countEventsMatching(string $prefix): int
    {
        return count(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with($event, $prefix),
        ));
    }

    #[QueryMethod('events-starting-with')]
    public function countEventsByPrefix(string $prefix): int
    {
        return $this->countEventsMatching($prefix);
    }
}
