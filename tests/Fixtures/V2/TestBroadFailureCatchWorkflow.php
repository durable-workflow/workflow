<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('resume')]
final class TestBroadFailureCatchWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var array<string, mixed>
     */
    private array $caught = [];

    public function handle(string $orderId): array
    {
        $this->stage = 'running-activity';

        try {
            activity(TestReplayedFailureActivity::class, $orderId);
        } catch (RuntimeException $exception) {
            $this->stage = 'waiting-for-resume';
            $this->caught = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ];
        }

        $resume = signal('resume');
        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'caught' => $this->caught,
            'resume' => $resume,
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'caught' => $this->caught,
        ];
    }
}
