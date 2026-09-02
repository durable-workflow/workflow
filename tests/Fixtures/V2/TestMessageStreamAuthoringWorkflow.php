<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestMessageStreamAuthoringWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $inbox = $this->inbox('chat');
        $peeked = $inbox->peek(10);
        $received = $inbox->receiveOne();

        $reply = null;

        if ($received !== null) {
            $reply = $this->outbox('chat.replies')
                ->sendReference(
                    $this->workflowId(),
                    'reply:' . $received->payload_reference,
                    metadata: [
                        'received_sequence' => $received->sequence,
                    ],
                );
        }

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'peeked' => $peeked->pluck('payload_reference')
                ->all(),
            'received' => $received?->payload_reference,
            'pending_after_receive' => $inbox->pendingCount(),
            'reply_payload_reference' => $reply?->payload_reference,
            'reply_stream_key' => $reply?->stream_key,
        ];
    }
}
