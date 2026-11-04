<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

/**
 * The first run takes one message and continues as new. The continued run
 * takes the next message, which was still pending at continue-as-new.
 */
#[Type('test-message-stream-continue-as-new-workflow')]
#[Signal('ping')]
final class TestMessageStreamContinueAsNewWorkflow extends Workflow
{
    /**
     * @param list<string|null> $received
     *
     * @return list<string|null>
     */
    public function handle(int $generation = 1, array $received = []): mixed
    {
        if ($generation === 1) {
            await('ping');
            $received[] = $this->inbox('chat')->receiveOne()?->payload_reference;

            return continueAsNew(2, $received);
        }

        $received[] = $this->inbox('chat')->receiveOne()?->payload_reference;

        return $received;
    }
}
