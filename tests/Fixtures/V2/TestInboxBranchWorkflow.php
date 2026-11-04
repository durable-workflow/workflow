<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

/**
 * Each turn waits for a ping and takes a different step depending on whether
 * the inbox had a message, so a replay that receives differently from the
 * original run takes a different step.
 */
#[Type('test-inbox-branch-workflow')]
#[Signal('ping')]
final class TestInboxBranchWorkflow extends Workflow
{
    /**
     * @return list<string>
     */
    public function handle(int $maxTurns = 2): array
    {
        $outcomes = [];

        while (count($outcomes) < $maxTurns) {
            await('ping');
            $message = $this->inbox('chat')
                ->receiveOne();

            $outcomes[] = $message === null
                ? 'none:' . activity(TestRedriveFirstActivity::class, 'none')
                : 'got:' . sideEffect(static fn (): string => (string) $message->payload_reference);
        }

        return $outcomes;
    }
}
