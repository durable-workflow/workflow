<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * Each turn waits for a ping, takes one message from the inbox, and runs an
 * activity with it.
 */
#[Type('test-inbox-turns-workflow')]
#[Signal('ping')]
final class TestInboxTurnsWorkflow extends Workflow
{
    /**
     * @var list<string|null>
     */
    private array $received = [];

    /**
     * @return list<string|null>
     */
    public function handle(int $maxTurns = 2): array
    {
        while (count($this->received) < $maxTurns) {
            await('ping');
            $message = $this->inbox('chat')
                ->receiveOne();
            $this->received[] = $message?->payload_reference;
            activity(TestRedriveFirstActivity::class, (string) $message?->payload_reference);
        }

        return $this->received;
    }

    /**
     * @return list<string|null>
     */
    #[QueryMethod('received')]
    public function received(): array
    {
        return $this->received;
    }
}
