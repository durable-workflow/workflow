<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Signal('resume')]
final class TestPendingTimerSignalWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(int $seconds): array
    {
        $this->stage = 'before-timer';
        $this->events[] = 'started';

        timer($seconds);

        $this->stage = 'after-timer';
        $this->events[] = 'timer-fired';

        $resume = signal('resume');

        $this->stage = 'completed';
        $this->events[] = sprintf('signal:%s', $resume);

        return [
            'stage' => $this->stage,
            'events' => $this->events,
        ];
    }

    #[QueryMethod]
    public function currentStage(): string
    {
        return $this->stage;
    }

    /**
     * @return list<string>
     */
    #[QueryMethod]
    public function currentEvents(): array
    {
        return $this->events;
    }
}
