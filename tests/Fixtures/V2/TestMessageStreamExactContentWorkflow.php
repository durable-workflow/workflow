<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * Sends references that a case- or accent-insensitive collation compares as
 * equal, then waits so a later task replays the sends.
 */
#[Type('test-message-stream-exact-content-workflow')]
#[Signal('go')]
final class TestMessageStreamExactContentWorkflow extends Workflow
{
    public const REFERENCES = ['doc:Readme', 'doc:README', 'doc:resume', 'doc:résumé', 'doc:a', 'doc:a '];

    /**
     * @return list<array{reference: string, id: int|string}>
     */
    public function handle(): array
    {
        $sent = [];

        foreach (self::REFERENCES as $reference) {
            $message = $this->outbox('docs')
                ->sendReference($this->workflowId(), $reference);
            $sent[] = [
                'reference' => (string) $message->payload_reference,
                'id' => $message->getKey(),
            ];
        }

        await('go');

        return $sent;
    }
}
