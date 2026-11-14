<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\now;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-redrive-fractional-clock-workflow')]
final class TestRedriveFractionalClockWorkflow extends Workflow
{
    /**
     * @var array<string, string>
     */
    private array $recordedTimes = [];

    /**
     * @return array<string, string>
     */
    public function handle(string $name, int $seconds): array
    {
        $this->recordedTimes['started_at'] = now()->format('U.u');
        $greeting = activity(TestRedriveFirstActivity::class, $name);
        $this->recordedTimes['activity_completed_at'] = now()->format('U.u');
        timer($seconds);
        $this->recordedTimes['timer_fired_at'] = now()->format('U.u');
        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            ...$this->recordedTimes,
        ];
    }

    /**
     * @return array<string, string>
     */
    #[QueryMethod]
    public function recordedTimes(): array
    {
        return $this->recordedTimes;
    }
}
