<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\MessageStream;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

/**
 * TestInboxBranchWorkflow with an inbox opened once and kept, so later turns
 * receive through a stream opened at an earlier step.
 */
#[Type('test-inbox-kept-branch-workflow')]
#[Signal('ping')]
final class TestInboxKeptBranchWorkflow extends Workflow
{
    private ?MessageStream $chat = null;

    /**
     * @return list<string>
     */
    public function handle(int $maxTurns = 3): array
    {
        $outcomes = [];

        while (count($outcomes) < $maxTurns) {
            await('ping');
            $this->chat ??= $this->inbox('chat');
            $message = $this->chat->receiveOne();

            $outcomes[] = $message === null
                ? 'none:' . activity(TestRedriveFirstActivity::class, 'none')
                : 'got:' . sideEffect(static fn (): string => (string) $message->payload_reference);
        }

        return $outcomes;
    }
}
