<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\MessageStream;
use Workflow\V2\Workflow;

/**
 * Keeps its outbox in a property. An update handler can be the first to open
 * it, and on replay the handler runs with command dispatch off.
 */
#[Type('test-message-stream-kept-stream-workflow')]
#[Signal('go')]
final class TestMessageStreamKeptStreamWorkflow extends Workflow
{
    private ?MessageStream $replies = null;

    /**
     * @return array{sent: string}
     */
    public function handle(): array
    {
        await('go');
        activity(TestRedriveFirstActivity::class, 'main');
        $sent = $this->replies()
            ->sendReference($this->workflowId(), 'ref:main', idempotencyKey: 'ref:main');

        return [
            'sent' => (string) $sent->payload_reference,
        ];
    }

    #[UpdateMethod('note')]
    public function note(string $note): string
    {
        $this->replies()
            ->sendReference($this->workflowId(), "note:{$note}", idempotencyKey: "note:{$note}");

        return "noted {$note}";
    }

    private function replies(): MessageStream
    {
        return $this->replies ??= $this->outbox('replies');
    }
}
