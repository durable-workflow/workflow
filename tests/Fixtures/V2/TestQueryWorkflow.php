<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-query-workflow')]
final class TestQueryWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var list<string>
     */
    private array $events = [];

    public function execute(): Generator
    {
        $this->stage = 'waiting-for-name';
        $this->events[] = 'started';

        $name = yield awaitSignal('name-provided');

        $this->stage = 'waiting-for-timer';
        $this->events[] = sprintf('name:%s', $name);

        yield timer(60);

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
}
