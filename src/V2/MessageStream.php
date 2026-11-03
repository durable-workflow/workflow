<?php

declare(strict_types=1);

namespace Workflow\V2;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageReplayLedger;
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
        private readonly ?MessageReplayLedger $replayLedger = null,
        private readonly bool $readOnly = false,
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

    /**
     * The stream workflow code uses. Workflow code runs again on every
     * replay and query, so a send or receive it already made returns the row
     * it wrote or consumed instead of acting again, and a read-only replay
     * (a query or a replay check) never writes.
     *
     * @internal
     */
    public static function forWorkflowCode(
        WorkflowRun $run,
        ?string $streamKey,
        ?MessageService $messages,
        int $defaultConsumedBySequence,
        MessageReplayLedger $replayLedger,
        bool $readOnly,
    ): self {
        return new self(
            $run,
            $streamKey ?? MessageStreamCursor::defaultStreamKey($run),
            $messages ?? new MessageService(),
            $defaultConsumedBySequence,
            $replayLedger,
            $readOnly,
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
        $limit = $this->positiveLimit($limit);

        if ($this->replayLedger instanceof MessageReplayLedger) {
            $sequence = $this->consumedBySequence($consumedBySequence);
            $recorded = $this->recordedReceive($sequence, $limit);

            // A receive this step already made returns what it consumed.
            if ($recorded->isNotEmpty() || $this->readOnly) {
                return $recorded;
            }

            $messages = $this->peek($limit);

            if ($messages->isNotEmpty()) {
                $this->messages->consumeMessageBatch($this->run, $messages->all(), $sequence);
                $this->replayLedger->advanceReceive($this->streamKey, $sequence, $messages->count());
            }

            return $messages;
        }

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
        if ($this->replayLedger instanceof MessageReplayLedger) {
            $channelValue = is_string($channel) ? $channel : $channel->value;
            $sent = $this->recordedSend(
                $channelValue,
                $targetInstanceId,
                $payloadReference,
                $correlationId,
                $idempotencyKey
            );

            // A send this run already made returns the row it wrote.
            if ($sent instanceof WorkflowMessage) {
                $this->replayLedger->claim($sent->getKey());

                return $sent;
            }

            // A read-only replay describes the send without writing it.
            if ($this->readOnly) {
                return new WorkflowMessage([
                    'workflow_instance_id' => $this->run->workflow_instance_id,
                    'workflow_run_id' => $this->run->id,
                    'direction' => MessageDirection::Outbound,
                    'channel' => $channelValue,
                    'stream_key' => $this->streamKey,
                    'source_workflow_instance_id' => $this->run->workflow_instance_id,
                    'source_workflow_run_id' => $this->run->id,
                    'target_workflow_instance_id' => $targetInstanceId,
                    'payload_reference' => $payloadReference,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'expires_at' => $expiresAt,
                    'consume_state' => MessageConsumeState::Pending,
                ]);
            }
        }

        $message = $this->messages->sendMessage(
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
        $this->replayLedger?->claim($message->getKey());

        return $message;
    }

    /**
     * The earliest outbound row this run wrote for the same send that this
     * execution has not matched yet.
     */
    private function recordedSend(
        string $channel,
        string $targetInstanceId,
        ?string $payloadReference,
        ?string $correlationId,
        ?string $idempotencyKey,
    ): ?WorkflowMessage {
        $query = WorkflowMessage::query()
            ->where('workflow_instance_id', $this->run->workflow_instance_id)
            ->where('source_workflow_run_id', $this->run->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('channel', $channel)
            ->where('stream_key', $this->streamKey)
            ->where('target_workflow_instance_id', $targetInstanceId);

        foreach ([
            'payload_reference' => $payloadReference,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
        ] as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        $claimed = $this->replayLedger?->claimedOutboundIds() ?? [];

        if ($claimed !== []) {
            $query->whereNotIn('id', $claimed);
        }

        /** @var WorkflowMessage|null $message */
        $message = $query->orderBy('sequence')
            ->orderBy('id')
            ->first();

        return $message;
    }

    /**
     * The messages this run consumed at this step for this stream that this
     * execution has not returned yet.
     *
     * @return Collection<int, WorkflowMessage>
     */
    private function recordedReceive(int $consumedBySequence, int $limit): Collection
    {
        $offset = $this->replayLedger?->receiveOffset($this->streamKey, $consumedBySequence) ?? 0;

        /** @var Collection<int, WorkflowMessage> $messages */
        $messages = WorkflowMessage::query()
            ->where('workflow_run_id', $this->run->id)
            ->where('stream_key', $this->streamKey)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('consume_state', MessageConsumeState::Consumed->value)
            ->where('consumed_by_sequence', $consumedBySequence)
            ->orderBy('sequence')
            ->skip($offset)
            ->take($limit)
            ->get();

        if ($messages->isNotEmpty()) {
            $this->replayLedger?->advanceReceive($this->streamKey, $consumedBySequence, $messages->count());
        }

        return $messages;
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
