<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * Receives and sends before an activity that fails, so a redrive successor
 * runs the receive and the send again over its reused prefix.
 */
#[Type('test-message-stream-redrive-workflow')]
#[Signal('go')]
final class TestMessageStreamRedriveWorkflow extends Workflow
{
    /**
     * @return array{received: string|null, sent: string, result: string}
     */
    public function handle(): array
    {
        await('go');
        $message = $this->inbox('chat')
            ->receiveOne();
        $greeting = activity(TestRedriveFirstActivity::class, (string) $message?->payload_reference);
        $sent = $this->outbox('replies')
            ->sendReference($this->workflowId(), 'ref:' . $message?->payload_reference, idempotencyKey: 'ref');

        return [
            'received' => $message?->payload_reference,
            'sent' => (string) $sent->payload_reference,
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
        ];
    }
}
