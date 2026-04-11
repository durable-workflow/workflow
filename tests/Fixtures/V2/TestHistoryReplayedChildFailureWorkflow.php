<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-history-child-failure-replay-workflow')]
final class TestHistoryReplayedChildFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @var array<string, mixed>
     */
    private array $caught = [];

    public function execute(string $orderId): array
    {
        $this->stage = 'running-child';

        try {
            child(TestReplayedFailureChildWorkflow::class, $orderId);
        } catch (TestReplayedDomainException $exception) {
            $this->stage = 'caught-child-failure';
            $this->caught = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'order_id' => $exception->orderId,
                'channel' => $exception->channel,
            ];
        }

        $this->stage = 'completed';

        return $this->currentState();
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
