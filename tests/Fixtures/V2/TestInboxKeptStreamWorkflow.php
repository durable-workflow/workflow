<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\MessageStream;
use Workflow\V2\Workflow;

/**
 * Like TestInboxTurnsWorkflow, but opens its inbox once and keeps it, so
 * later turns receive through a stream created at an earlier step.
 */
#[Type('test-inbox-kept-stream-workflow')]
#[Signal('ping')]
final class TestInboxKeptStreamWorkflow extends Workflow
{
    private ?MessageStream $chat = null;

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
            $this->chat ??= $this->inbox('chat');
            $message = $this->chat->receiveOne();
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
