<?php

declare(strict_types=1);

namespace Workflow\V2;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageService;
use Workflow\V2\Support\MessageStreamCursor;

/**
 * First-class v2 durable message stream facade.
 *
 * @api Stable v2 authoring API for repeated human input and workflow-to-workflow message streams.
 */
final class MessageStream
{
    public function __construct(
        private readonly WorkflowRun $run,
        private readonly string $streamKey,
        private readonly MessageService $messages = new MessageService(),
        private readonly ?int $defaultConsumedBySequence = null,
    ) {
        if ($streamKey === '') {
            throw new InvalidArgumentException('Message stream key must not be empty.');
        }
    }

    public static function forRun(
        WorkflowRun $run,
        ?string $streamKey = null,
        ?MessageService $messages = null,
        ?int $defaultConsumedBySequence = null,
    ): self {
        return new self(
            $run,
            $streamKey ?? MessageStreamCursor::defaultStreamKey($run),
            $messages ?? new MessageService(),
            $defaultConsumedBySequence,
        );
    }

    public function key(): string
    {
        return $this->streamKey;
    }

    public function cursor(): int
    {
        return MessageStreamCursor::positionForRun($this->run);
    }

    public function hasPending(): bool
    {
        return $this->messages->hasUnconsumedMessages($this->run, $this->streamKey);
    }

    public function pendingCount(): int
    {
        return $this->messages->getUnconsumedCount($this->run, $this->streamKey);
    }

    /**
     * Inspect pending inbound messages without consuming them.
     *
     * @return Collection<int, WorkflowMessage>
     */
    public function peek(int $limit = 100): Collection
    {
        return $this->messages->receiveMessages($this->run, $this->streamKey, $this->positiveLimit($limit));
    }

    /**
     * Receive and consume pending inbound messages.
     *
     * @return Collection<int, WorkflowMessage>
     */
    public function receive(int $limit = 1, ?int $consumedBySequence = null): Collection
    {
        $messages = $this->peek($limit);

        if ($messages->isEmpty()) {
            return $messages;
        }

        $this->messages->consumeMessageBatch(
            $this->run,
            $messages->all(),
            $this->consumedBySequence($consumedBySequence),
        );

        return $messages;
    }

    public function receiveOne(?int $consumedBySequence = null): ?WorkflowMessage
    {
        /** @var WorkflowMessage|null $message */
        $message = $this->receive(1, $consumedBySequence)
            ->first();

        return $message;
    }

    /**
     * Send a payload-reference message to another workflow instance.
     *
     * Message payload bytes live outside `workflow_messages`; this method
     * stores the durable pointer plus stream ordering/correlation metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function sendReference(
        string $targetInstanceId,
        ?string $payloadReference = null,
        MessageChannel|string $channel = MessageChannel::WorkflowMessage,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?DateTimeInterface $expiresAt = null,
    ): WorkflowMessage {
        return $this->messages->sendMessage(
            $this->run,
            $channel,
            $targetInstanceId,
            $payloadReference,
            $this->streamKey,
            $correlationId,
            $idempotencyKey,
            $metadata,
            $expiresAt,
        );
    }

    private function consumedBySequence(?int $override): int
    {
        $sequence = $override ?? $this->defaultConsumedBySequence;

        if ($sequence === null || $sequence < 1) {
            throw new InvalidArgumentException(
                'Receiving a durable message requires a positive workflow history sequence.',
            );
        }

        return $sequence;
    }

    private function positiveLimit(int $limit): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Message receive limit must be at least 1.');
        }

        return $limit;
    }
}
