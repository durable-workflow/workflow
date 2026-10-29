<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use WeakMap;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryEventPayloadContract;
use Workflow\V2\Support\HistoryPayloadJson;
use Workflow\V2\Support\HistoryRecordedAt;
use Workflow\V2\Support\HistoryTransactionMeter;
use Workflow\V2\Support\LinkedServiceCallCloser;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\PendingSignalCloser;
use Workflow\V2\Support\ServiceCallWorkflowDelivery;
use Workflow\V2\Support\TerminalCancellationHistory;

class WorkflowHistoryEvent extends Model
{
    use ResolvesStorageConnection;

    use HasUlids;

    private const MAX_CACHED_PAYLOAD_JSON_BYTES = 4_096;

    private const MAX_CACHED_PAYLOAD_VALUES = 128;

    private const MAX_CACHED_PAYLOAD_MODELS = 256;

    public $incrementing = false;

    protected $table = 'workflow_history_events';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'event_type' => HistoryEventType::class,
        'payload' => 'array',
        'recorded_at' => HistoryRecordedAt::class,
    ];

    /**
     * @var WeakMap<self, array{json: string, value: array<array-key, mixed>}>|null
     */
    private static ?WeakMap $payloadCache = null;

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
        return self::recordEvent($run, $eventType, $payload, $task, $command, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function recordCancellation(
        WorkflowRun $run,
        HistoryEventType $eventType,
        array $payload,
        WorkflowTask|string|null $task = null,
        WorkflowCommand|string|null $command = null,
        ?TerminalCancellationHistory $terminalHistory = null,
    ): self {
        if (! in_array($eventType, [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled], true)) {
            throw new \LogicException('Terminal cancellation recording requires a cancellation event.');
        }
        $terminalHistory?->assertRun($run);

        return self::recordEvent($run, $eventType, $payload, $task, $command, $terminalHistory);
    }

    /**
     * @internal Validate one terminal operation's reusable command snapshot.
     * @param array<string, mixed> $snapshot
     */
    public static function matchesCommandSnapshot(WorkflowCommand $command, array $snapshot): bool
    {
        return $snapshot === self::commandSnapshot($command);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        // Configured models keep their own casting and accessor hooks.
        if ($key !== 'payload' || static::class !== self::class) {
            return parent::castAttribute($key, $value);
        }

        if (($this->getCasts()['payload'] ?? null) !== 'array'
            || ! is_string($value)
            || strlen($value) > self::MAX_CACHED_PAYLOAD_JSON_BYTES
            || (class_exists(Json::class) && ! HistoryPayloadJson::usesDefaultDecoder())) {
            if (self::$payloadCache !== null) {
                unset(self::$payloadCache[$this]);
            }

            return parent::castAttribute($key, $value);
        }

        $cached = self::$payloadCache[$this] ?? null;
        if (($cached['json'] ?? null) !== $value) {
            // Let Laravel decode before publishing any reusable value. Keep
            // raw attributes unchanged for mutation, dirty checks and storage.
            $decoded = parent::castAttribute($key, $value);
            if (! is_array($decoded) || count($decoded, COUNT_RECURSIVE) > self::MAX_CACHED_PAYLOAD_VALUES) {
                if (self::$payloadCache !== null) {
                    unset(self::$payloadCache[$this]);
                }

                return $decoded;
            }
            self::$payloadCache ??= new WeakMap();
            if (! isset(self::$payloadCache[$this]) && count(self::$payloadCache) >= self::MAX_CACHED_PAYLOAD_MODELS) {
                // A per-model bound alone still retains thousands of decoded
                // small payloads in long histories. Weak keys keep the cache
                // from extending model lifetimes; eviction preserves raw data.
                foreach (self::$payloadCache as $oldModel => $unused) {
                    unset(self::$payloadCache[$oldModel]);
                    break;
                }
            }
            self::$payloadCache[$this] = [
                'json' => $value,
                'value' => $decoded,
            ];

            return $decoded;
        }

        return $cached['value'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function recordEvent(
        WorkflowRun $run,
        HistoryEventType $eventType,
        array $payload,
        WorkflowTask|string|null $task,
        WorkflowCommand|string|null $command,
        ?TerminalCancellationHistory $terminalHistory,
    ): self {
        if ($eventType === HistoryEventType::MemoUpserted) {
            foreach (['entries', 'merged'] as $field) {
                if (! is_array($payload[$field] ?? null)) {
                    throw new \InvalidArgumentException(sprintf(
                        'MemoUpserted history field [%s] must be an Avro payload envelope.',
                        $field,
                    ));
                }

                $payload[$field] = MemoPayload::isInlineEnvelope($payload[$field])
                    ? MemoPayload::canonicalMapEnvelope($payload[$field])
                    : MemoPayload::mapEnvelope($payload[$field]);
            }
        }

        $taskModel = $task instanceof WorkflowTask ? $task : null;
        $commandModel = $command instanceof WorkflowCommand ? $command : null;
        $taskId = $taskModel?->id ?? (is_string($task) ? $task : null);
        $commandId = $commandModel?->id ?? (is_string($command) ? $command : null);
        HistoryEventPayloadContract::assertKnownPayloadKeys($eventType, $payload);

        /** @var self $event */
        $event = ConfiguredV2Models::query('history_event_model', self::class)
            ->getModel()
            ->getConnection()
            ->transaction(static function () use (
                $run,
                $eventType,
                $payload,
                $taskModel,
                $commandModel,
                $taskId,
                $commandId,
                $terminalHistory,
            ): self {
                /** @var WorkflowRun $lockedRun */
                $lockedRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($run->id);
                $now = now();
                $sequence = self::nextSequenceForRun($lockedRun);
                $storedPayload = self::snapshotPayload($payload, $taskModel, $commandModel, $terminalHistory);
                // A metered completion (#930) charges the event as stored,
                // and the run row whose history position it advances.
                $meter = HistoryTransactionMeter::current();
                $meter?->chargeEvent($eventType->value, $storedPayload);
                $meter?->chargeRows(1);

                /** @var self $event */
                $event = ConfiguredV2Models::query('history_event_model', self::class)->create([
                    'workflow_run_id' => $lockedRun->id,
                    'sequence' => $sequence,
                    'event_type' => $eventType->value,
                    'payload' => $storedPayload,
                    'workflow_task_id' => $taskId,
                    'workflow_command_id' => $commandId,
                    'recorded_at' => $now,
                ]);
                ExecutionTracing::observeOutcome($lockedRun, $taskModel, $event);

                $lockedRun->forceFill([
                    'last_history_sequence' => $sequence,
                    'last_progress_at' => $now,
                ])->save();

                PendingSignalCloser::closeForTerminalEvent($lockedRun, $event, $taskModel);

                $run->forceFill([
                    'last_history_sequence' => $lockedRun->last_history_sequence,
                    'last_progress_at' => $lockedRun->last_progress_at,
                ]);
                $run->syncOriginalAttributes(['last_history_sequence', 'last_progress_at']);

                LinkedServiceCallCloser::closeForTerminalEvent($lockedRun, $event);
                ServiceCallWorkflowDelivery::forAdmission($lockedRun, $event);

                return $event;
            });

        return $event;
    }

    private static function nextSequenceForRun(WorkflowRun $run): int
    {
        $storedMax = (int) (ConfiguredV2Models::query('history_event_model', self::class)
            ->where('workflow_run_id', $run->id)
            ->max('sequence') ?? 0);

        return max((int) ($run->last_history_sequence ?? 0), $storedMax) + 1;
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
        ?TerminalCancellationHistory $terminalHistory,
    ): array {
        if ($task !== null && ! array_key_exists('task', $payload)) {
            $payload['task'] = self::taskSnapshot($task);
        }

        if ($command !== null && ! array_key_exists('command', $payload)) {
            $commandPayload = $terminalHistory?->commandPayload($command) ?? [];
            $payload['command'] = $commandPayload['command'] ?? self::commandSnapshot($command);
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
        $payloadStoredExternally = self::commandPayloadStoredExternally($command);

        return array_filter([
            'id' => $command->id,
            'sequence' => $command->command_sequence,
            'message_sequence' => $command->message_sequence,
            'type' => $command->command_type?->value,
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'target_name' => $payloadStoredExternally ? null : $command->targetName(),
            'payload_codec' => $command->payload_codec,
            'payload' => $payloadStoredExternally && is_string($command->payload)
                ? ExternalPayloads::storedEnvelope($command->payload)
                : $command->payload,
            'source' => $command->source,
            'context' => $publicContext === [] ? null : $publicContext,
            'caller_label' => $command->callerLabel(),
            'principal_type' => $command->principalType(),
            'principal_id' => $command->principalId(),
            'principal_label' => $command->principalLabel(),
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

    private static function commandPayloadStoredExternally(WorkflowCommand $command): bool
    {
        return is_string($command->payload)
            && $command->payload !== ''
            && ExternalPayloads::isStoredReference($command->payload);
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
