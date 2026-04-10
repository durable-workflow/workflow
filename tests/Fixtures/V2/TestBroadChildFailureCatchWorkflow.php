<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use RuntimeException;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Signal('resume')]
final class TestBroadChildFailureCatchWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var array<string, mixed>
     */
    private array $caught = [];

    public function execute(string $orderId): Generator
    {
        $this->stage = 'running-child';

        try {
            yield child(TestReplayedFailureChildWorkflow::class, $orderId);
        } catch (RuntimeException $exception) {
            $this->stage = 'waiting-for-resume';
            $this->caught = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ];
        }

        $resume = yield awaitSignal('resume');
        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'caught' => $this->caught,
            'resume' => $resume,
        ];
    }

    /**
     * @return array{stage: string, caught: array<string, mixed>}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'caught' => $this->caught,
        ];
    }
}
