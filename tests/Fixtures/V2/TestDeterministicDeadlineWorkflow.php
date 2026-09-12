<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestDeterministicDeadlineWorkflow extends Workflow
{
    public int $startedAtMs;

    public int $deadlineMs;

    public ?int $completedAtMs = null;

    public function handle(): array
    {
        $this->startedAtMs = self::now()->getTimestampMs();
        $deadline = self::now()->addHour();
        $this->deadlineMs = $deadline->getTimestampMs();

        while (self::now()->lessThan($deadline)) {
            timer(1800);
        }

        $this->completedAtMs = self::now()->getTimestampMs();

        return [
            'started_at_ms' => $this->startedAtMs,
            'deadline_ms' => $this->deadlineMs,
            'completed_at_ms' => $this->completedAtMs,
        ];
    }
}
