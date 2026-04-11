<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowHistoryEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_history_events';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'event_type' => HistoryEventType::class,
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            'workflow_command_id',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(
        WorkflowRun $run,
        HistoryEventType $eventType,
        array $payload = [],
        WorkflowTask|string|null $task = null,
        WorkflowCommand|string|null $command = null,
    ): self {
        $taskModel = $task instanceof WorkflowTask ? $task : null;
        $commandModel = $command instanceof WorkflowCommand ? $command : null;
        $taskId = $taskModel?->id ?? (is_string($task) ? $task : null);
        $commandId = $commandModel?->id ?? (is_string($command) ? $command : null);
        $sequence = $run->last_history_sequence + 1;

        /** @var self $event */
        $event = self::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'event_type' => $eventType->value,
            'payload' => self::snapshotPayload($payload, $taskModel, $commandModel),
            'workflow_task_id' => $taskId,
            'workflow_command_id' => $commandId,
            'recorded_at' => now(),
        ]);

        $run->forceFill([
            'last_history_sequence' => $sequence,
            'last_progress_at' => now(),
        ])->save();

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function snapshotPayload(
        array $payload,
        ?WorkflowTask $task,
        ?WorkflowCommand $command,
    ): array {
        if ($task !== null && ! array_key_exists('task', $payload)) {
            $payload['task'] = self::taskSnapshot($task);
        }

        if ($command !== null && ! array_key_exists('command', $payload)) {
            $payload['command'] = self::commandSnapshot($command);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function taskSnapshot(WorkflowTask $task): array
    {
        return array_filter([
            'id' => $task->id,
            'type' => $task->task_type?->value,
            'status' => $task->status?->value,
            'available_at' => self::timestamp($task->available_at),
            'leased_at' => self::timestamp($task->leased_at),
            'lease_owner' => $task->lease_owner,
            'lease_expires_at' => self::timestamp($task->lease_expires_at),
            'attempt_count' => $task->attempt_count,
            'repair_count' => $task->repair_count,
            'connection' => $task->connection,
            'queue' => $task->queue,
            'compatibility' => $task->compatibility,
            'last_dispatch_attempt_at' => self::timestamp($task->last_dispatch_attempt_at),
            'last_dispatched_at' => self::timestamp($task->last_dispatched_at),
            'last_dispatch_error' => $task->last_dispatch_error,
            'last_claim_failed_at' => self::timestamp($task->last_claim_failed_at),
            'last_claim_error' => $task->last_claim_error,
            'repair_available_at' => self::timestamp($task->repair_available_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function commandSnapshot(WorkflowCommand $command): array
    {
        $publicContext = $command->publicContext();

        return array_filter([
            'id' => $command->id,
            'sequence' => $command->command_sequence,
            'type' => $command->command_type?->value,
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'target_name' => $command->targetName(),
            'payload_codec' => $command->payload_codec,
            'payload' => $command->payload,
            'source' => $command->source,
            'context' => $publicContext === [] ? null : $publicContext,
            'caller_label' => $command->callerLabel(),
            'auth_status' => $command->authStatus(),
            'auth_method' => $command->authMethod(),
            'request_method' => $command->requestMethod(),
            'request_path' => $command->requestPath(),
            'request_route_name' => $command->requestRouteName(),
            'request_fingerprint' => $command->requestFingerprint(),
            'request_id' => $command->requestId(),
            'correlation_id' => $command->correlationId(),
            'status' => $command->status?->value,
            'outcome' => $command->outcome?->value,
            'rejection_reason' => $command->rejection_reason,
            'accepted_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
