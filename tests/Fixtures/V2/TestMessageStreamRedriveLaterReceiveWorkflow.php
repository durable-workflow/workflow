<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * Receives once before an activity that fails, and once after it, so a
 * message that arrives while the run is failed is received by the redrive
 * successor.
 */
#[Type('test-message-stream-redrive-later-receive-workflow')]
#[Signal('go')]
final class TestMessageStreamRedriveLaterReceiveWorkflow extends Workflow
{
    /**
     * @return array{first: string|null, later: string|null, result: string}
     */
    public function handle(): array
    {
        await('go');
        $first = $this->inbox('chat')
            ->receiveOne();
        $greeting = activity(TestRedriveFirstActivity::class, (string) $first?->payload_reference);
        $result = activity(TestRedriveSecondActivity::class, $greeting);
        $later = $this->inbox('chat')
            ->receiveOne();

        return [
            'first' => $first?->payload_reference,
            'later' => $later?->payload_reference,
            'result' => $result,
        ];
    }
}
