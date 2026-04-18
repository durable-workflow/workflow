<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowMessage extends Model
{
    // Structural limits
    public const MAX_MESSAGES_PER_STREAM = 10000;

    public const MAX_PAYLOAD_REFERENCE_LENGTH = 191;

    public const MAX_CORRELATION_ID_LENGTH = 191;

    public $incrementing = true;

    protected $table = 'workflow_messages';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'direction' => MessageDirection::class,
        'consume_state' => MessageConsumeState::class,
        'sequence' => 'integer',
        'consumed_by_sequence' => 'integer',
        'delivery_attempt_count' => 'integer',
        'metadata' => 'array',
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_delivery_attempt_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'workflow_instance_id',
        );
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'source_workflow_run_id',
        );
    }

    public function targetRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'target_workflow_run_id',
        );
    }

    /**
     * Check if message is consumable.
     */
    public function isConsumable(): bool
    {
        if ($this->consume_state !== MessageConsumeState::Pending) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Mark message as consumed.
     */
    public function markConsumed(int $consumedBySequence): void
    {
        $this->forceFill([
            'consume_state' => MessageConsumeState::Consumed,
            'consumed_at' => now(),
            'consumed_by_sequence' => $consumedBySequence,
        ])->save();
    }

    /**
     * Mark message as failed.
     */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'consume_state' => MessageConsumeState::Failed,
            'last_delivery_error' => $error,
            'last_delivery_attempt_at' => now(),
            'delivery_attempt_count' => $this->delivery_attempt_count + 1,
        ])->save();
    }

    /**
     * Mark message as expired.
     */
    public function markExpired(): void
    {
        $this->forceFill([
            'consume_state' => MessageConsumeState::Expired,
        ])->save();
    }

    /**
     * Record delivery attempt.
     */
    public function recordDeliveryAttempt(?string $error = null): void
    {
        $this->forceFill([
            'delivery_attempt_count' => $this->delivery_attempt_count + 1,
            'last_delivery_attempt_at' => now(),
            'last_delivery_error' => $error,
        ])->save();
    }

    /**
     * Get unconsumed messages for a run within a stream.
     */
    public static function getUnconsumedForStream(
        WorkflowRun $run,
        string $streamKey,
        int $afterSequence = 0,
        int $limit = 100,
    ): \Illuminate\Database\Eloquent\Collection {
        return static::where('workflow_run_id', $run->id)
            ->where('stream_key', $streamKey)
            ->where('direction', MessageDirection::Inbound)
            ->where('consume_state', MessageConsumeState::Pending)
            ->where('sequence', '>', $afterSequence)
            ->orderBy('sequence', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get unconsumed message count for a stream.
     */
    public static function getUnconsumedCountForStream(
        WorkflowRun $run,
        string $streamKey,
        int $afterSequence = 0,
    ): int {
        return static::where('workflow_run_id', $run->id)
            ->where('stream_key', $streamKey)
            ->where('direction', MessageDirection::Inbound)
            ->where('consume_state', MessageConsumeState::Pending)
            ->where('sequence', '>', $afterSequence)
            ->count();
    }

    /**
     * Check if stream has unconsumed messages.
     */
    public static function hasUnconsumedMessages(
        WorkflowRun $run,
        string $streamKey,
        int $afterSequence = 0,
    ): bool {
        return static::where('workflow_run_id', $run->id)
            ->where('stream_key', $streamKey)
            ->where('direction', MessageDirection::Inbound)
            ->where('consume_state', MessageConsumeState::Pending)
            ->where('sequence', '>', $afterSequence)
            ->exists();
    }

    /**
     * Expire old pending messages based on expires_at timestamp.
     */
    public static function expireStaleMessages(): int
    {
        return static::where('consume_state', MessageConsumeState::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'consume_state' => MessageConsumeState::Expired->value,
                'updated_at' => now(),
            ]);
    }
}
