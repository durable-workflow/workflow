<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\MessageStream;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

class MessageService
{
    public function stream(
        WorkflowRun $run,
        ?string $streamKey = null,
        ?int $defaultConsumedBySequence = null,
    ): MessageStream {
        return MessageStream::forRun($run, $streamKey, $this, $defaultConsumedBySequence);
    }

    /**
     * Send an outbound message from a workflow run.
     *
     * @param WorkflowRun $sourceRun The sending workflow run
     * @param MessageChannel|string $channel The message channel
     * @param string $targetInstanceId Target workflow instance ID
     * @param string|null $payloadReference Payload reference
     * @param string|null $streamKey Stream key (defaults to instance:{targetInstanceId})
     * @param string|null $correlationId Correlation ID for tracking
     * @param string|null $idempotencyKey Idempotency key for deduplication
     * @param array $metadata Additional metadata
     * @param \DateTimeInterface|null $expiresAt Optional expiry timestamp
     *
     * @return WorkflowMessage The created outbound message
     */
    public function sendMessage(
        WorkflowRun $sourceRun,
        MessageChannel|string $channel,
        string $targetInstanceId,
        ?string $payloadReference = null,
        ?string $streamKey = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?\DateTimeInterface $expiresAt = null,
    ): WorkflowMessage {
        $channel = is_string($channel) ? $channel : $channel->value;
        $streamKey = $streamKey ?? "instance:{$targetInstanceId}";

        return DB::transaction(static function () use (
            $sourceRun,
            $channel,
            $targetInstanceId,
            $payloadReference,
            $streamKey,
            $correlationId,
            $idempotencyKey,
            $metadata,
            $expiresAt,
        ): WorkflowMessage {
            $instanceIds = array_values(array_unique([$sourceRun->workflow_instance_id, $targetInstanceId]));
            sort($instanceIds);

            /** @var \Illuminate\Support\Collection<string, WorkflowInstance> $instances */
            $instances = WorkflowInstance::whereIn('id', $instanceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var WorkflowInstance $sourceInstance */
            $sourceInstance = $instances->get($sourceRun->workflow_instance_id)
                ?? WorkflowInstance::where('id', $sourceRun->workflow_instance_id)->firstOrFail();
            /** @var WorkflowInstance $targetInstance */
            $targetInstance = $instances->get($targetInstanceId)
                ?? WorkflowInstance::where('id', $targetInstanceId)->firstOrFail();

            $outboundSequence = MessageStreamCursor::reserveNextSequence($sourceInstance);
            $inboundSequence = $sourceRun->workflow_instance_id === $targetInstanceId
                ? MessageStreamCursor::reserveNextSequence($sourceInstance)
                : MessageStreamCursor::reserveNextSequence($targetInstance);

            // Create outbound message
            $message = new WorkflowMessage([
                'workflow_instance_id' => $sourceRun->workflow_instance_id,
                'workflow_run_id' => $sourceRun->id,
                'direction' => MessageDirection::Outbound,
                'channel' => $channel,
                'stream_key' => $streamKey,
                'sequence' => $outboundSequence,
                'source_workflow_instance_id' => $sourceRun->workflow_instance_id,
                'source_workflow_run_id' => $sourceRun->id,
                'target_workflow_instance_id' => $targetInstanceId,
                'payload_reference' => $payloadReference,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
                'expires_at' => $expiresAt,
                'consume_state' => MessageConsumeState::Pending,
            ]);

            $message->save();

            // Also create corresponding inbound message for target. If the
            // target instance has no current run yet (signal-with-start),
            // workflow_run_id stays null and is claimed when a run first
            // consumes from the instance's inbox.
            $inboundMessage = new WorkflowMessage([
                'workflow_instance_id' => $targetInstanceId,
                'workflow_run_id' => $targetInstance->current_run_id,
                'direction' => MessageDirection::Inbound,
                'channel' => $channel,
                'stream_key' => $streamKey,
                'sequence' => $inboundSequence,
                'source_workflow_instance_id' => $sourceRun->workflow_instance_id,
                'source_workflow_run_id' => $sourceRun->id,
                'target_workflow_instance_id' => $targetInstanceId,
                'payload_reference' => $payloadReference,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
                'expires_at' => $expiresAt,
                'consume_state' => MessageConsumeState::Pending,
            ]);

            $inboundMessage->save();

            return $message;
        });
    }

    /**
     * Receive unconsumed inbound messages for a workflow run.
     *
     * @param WorkflowRun $run The receiving workflow run
     * @param string|null $streamKey Stream key (defaults to instance stream)
     * @param int $limit Maximum number of messages to fetch
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowMessage>
     */
    public function receiveMessages(
        WorkflowRun $run,
        ?string $streamKey = null,
        int $limit = 100,
    ): \Illuminate\Database\Eloquent\Collection {
        $streamKey = $streamKey ?? "instance:{$run->workflow_instance_id}";
        $afterSequence = MessageStreamCursor::positionForRun($run);

        return WorkflowMessage::getUnconsumedForStream($run, $streamKey, $afterSequence, $limit);
    }

    /**
     * Consume a message and advance the cursor.
     *
     * @param WorkflowRun $run The consuming workflow run
     * @param WorkflowMessage $message The message to consume
     * @param int $consumedBySequence The history sequence that consumed this message
     */
    public function consumeMessage(WorkflowRun $run, WorkflowMessage $message, int $consumedBySequence): void
    {
        if (! $message->isConsumable()) {
            throw new InvalidArgumentException(sprintf(
                'Message %d is not consumable (state: %s)',
                $message->id,
                $message->consume_state->value,
            ));
        }

        if ($message->workflow_run_id !== $run->id) {
            throw new InvalidArgumentException(sprintf(
                'Message %d does not belong to run %s',
                $message->id,
                $run->id,
            ));
        }

        DB::transaction(static function () use ($run, $message, $consumedBySequence): void {
            // Mark message as consumed
            $message->markConsumed($consumedBySequence);

            // Advance cursor to this message's sequence
            MessageStreamCursor::advanceCursor($run, $message->sequence, null, $message->stream_key);
        });
    }

    /**
     * Consume multiple messages in batch and advance cursor to the highest sequence.
     *
     * @param WorkflowRun $run The consuming workflow run
     * @param array<WorkflowMessage> $messages Messages to consume
     * @param int $consumedBySequence The history sequence that consumed these messages
     */
    public function consumeMessageBatch(WorkflowRun $run, array $messages, int $consumedBySequence): void
    {
        if (empty($messages)) {
            return;
        }

        $maxSequence = 0;
        $streamKey = null;

        DB::transaction(static function () use (
            $run,
            $messages,
            $consumedBySequence,
            &$maxSequence,
            &$streamKey
        ): void {
            foreach ($messages as $message) {
                if (! $message instanceof WorkflowMessage) {
                    throw new InvalidArgumentException('All items must be WorkflowMessage instances');
                }

                if (! $message->isConsumable()) {
                    throw new InvalidArgumentException(sprintf(
                        'Message %d is not consumable (state: %s)',
                        $message->id,
                        $message->consume_state->value,
                    ));
                }

                if ($message->workflow_run_id !== $run->id) {
                    throw new InvalidArgumentException(sprintf(
                        'Message %d does not belong to run %s',
                        $message->id,
                        $run->id,
                    ));
                }

                $message->markConsumed($consumedBySequence);

                if ($message->sequence > $maxSequence) {
                    $maxSequence = $message->sequence;
                    $streamKey = $message->stream_key;
                }
            }

            // Advance cursor to highest consumed sequence
            if ($maxSequence > 0 && $streamKey !== null) {
                MessageStreamCursor::advanceCursor($run, $maxSequence, null, $streamKey);
            }
        });
    }

    /**
     * Get count of unconsumed messages for a stream.
     *
     * @param WorkflowRun $run The workflow run
     * @param string|null $streamKey Stream key (defaults to instance stream)
     *
     * @return int Unconsumed message count
     */
    public function getUnconsumedCount(WorkflowRun $run, ?string $streamKey = null): int
    {
        $streamKey = $streamKey ?? "instance:{$run->workflow_instance_id}";
        $afterSequence = MessageStreamCursor::positionForRun($run);

        return WorkflowMessage::getUnconsumedCountForStream($run, $streamKey, $afterSequence);
    }

    /**
     * Check if a stream has unconsumed messages.
     *
     * @param WorkflowRun $run The workflow run
     * @param string|null $streamKey Stream key (defaults to instance stream)
     *
     * @return bool True if unconsumed messages exist
     */
    public function hasUnconsumedMessages(WorkflowRun $run, ?string $streamKey = null): bool
    {
        $streamKey = $streamKey ?? "instance:{$run->workflow_instance_id}";
        $afterSequence = MessageStreamCursor::positionForRun($run);

        return WorkflowMessage::hasUnconsumedMessages($run, $streamKey, $afterSequence);
    }

    /**
     * Transfer messages from closing run to continued run (continue-as-new).
     *
     * Updates workflow_run_id for pending inbound messages and transfers cursor.
     *
     * @param WorkflowRun $closingRun The run being closed
     * @param WorkflowRun $continuedRun The new run from continue-as-new
     */
    public function transferMessagesToContinuedRun(WorkflowRun $closingRun, WorkflowRun $continuedRun): void
    {
        DB::transaction(static function () use ($closingRun, $continuedRun): void {
            // Transfer pending inbound messages to new run
            WorkflowMessage::where('workflow_run_id', $closingRun->id)
                ->where('direction', MessageDirection::Inbound)
                ->where('consume_state', MessageConsumeState::Pending)
                ->update([
                    'workflow_run_id' => $continuedRun->id,
                    'updated_at' => now(),
                ]);

            // Transfer message cursor position
            MessageStreamCursor::transferCursor($closingRun, $continuedRun);
        });
    }

    /**
     * Get messages by correlation ID.
     *
     * @param string $correlationId Correlation ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowMessage>
     */
    public function getMessagesByCorrelationId(string $correlationId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkflowMessage::where('correlation_id', $correlationId)
            ->orderBy('sequence', 'asc')
            ->get();
    }

    /**
     * Get messages for a stream.
     *
     * @param string $instanceId Workflow instance ID
     * @param string $streamKey Stream key
     * @param int $limit Maximum number of messages
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowMessage>
     */
    public function getMessagesForStream(
        string $instanceId,
        string $streamKey,
        int $limit = 100,
    ): \Illuminate\Database\Eloquent\Collection {
        return WorkflowMessage::where('workflow_instance_id', $instanceId)
            ->where('stream_key', $streamKey)
            ->orderBy('sequence', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Expire stale messages based on expires_at timestamp.
     *
     * Should be called periodically by a cleanup job.
     *
     * @return int Number of messages expired
     */
    public function expireStaleMessages(): int
    {
        return WorkflowMessage::expireStaleMessages();
    }
}
