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
 * One outbound reference per turn, in the pattern the Message Streams guide
 * and the sample app use: wait for a message, run an activity, then send.
 */
#[Type('test-message-stream-turns-workflow')]
#[Signal('message')]
final class TestMessageStreamTurnsWorkflow extends Workflow
{
    private int $turns = 0;

    /**
     * @return array{turns: int, sent: list<string>}
     */
    public function handle(int $maxTurns = 3, int $copies = 1): array
    {
        $sent = [];

        while ($this->turns < $maxTurns) {
            $text = await('message');
            activity(TestRedriveFirstActivity::class, (string) $text);
            ++$this->turns;
            // Identical sends are separate logical sends.
            for ($copy = 0; $copy < $copies; ++$copy) {
                $reply = $this->outbox('conversation.replies')
                    ->sendReference($this->workflowId(), "ref:{$this->turns}", idempotencyKey: "ref:{$this->turns}");
                $sent[] = (string) $reply->payload_reference;
            }
        }

        return [
            'turns' => $this->turns,
            'sent' => $sent,
        ];
    }

    #[QueryMethod('status')]
    public function status(): int
    {
        return $this->turns;
    }
}
