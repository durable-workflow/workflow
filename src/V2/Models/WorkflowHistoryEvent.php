<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Collection;
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
use Workflow\V2\Support\OpenActivityHistoryIndex;
use Workflow\V2\Support\PendingSignalCloser;
use Workflow\V2\Support\ServiceCallWorkflowDelivery;
use Workflow\V2\Support\TerminalCancellationHistory;
use Workflow\V2\Support\WorkflowSignalReadScope;

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

    protected $hidden = ['indexed_activity_execution_id', 'indexed_step_sequence'];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'event_type' => HistoryEventType::class,
        'indexed_step_sequence' => 'integer',
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
     * Locking reads require the caller to hold the run lock.
     *
     * @return Collection<int, WorkflowHistoryEvent>|null
     */
    public static function indexedForActivity(
        WorkflowRun $run,
        string $activityId,
        bool $lockForUpdate = false
    ): ?Collection {
        if (! self::activityIndexCovers($run)) {
            return null;
        }

        $query = $run->historyEvents()
            ->where('indexed_activity_execution_id', $activityId)
            ->where('sequence', '<=', (int) $run->last_history_sequence);
        if ($lockForUpdate) {
            $model = $query->getRelated();
            $connection = $model->getConnection();
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $query->forceIndex(self::activityIndexName($model->getTable(), $connection->getTablePrefix()));
            }
            $query->lockForUpdate();
        }

        $events = $query->get()
            ->filter(static fn (self $event): bool => $event->indexed_activity_execution_id === $activityId)
            ->values();

        return $events->isEmpty() ? null : $events;
    }

    public static function activityIndexCovers(WorkflowRun $run): bool
    {
        return $run->exists && isset($run->activity_history_index_through)
            && (int) $run->activity_history_index_through === (int) $run->last_history_sequence
            && $run->details_pruned_at === null;
    }

    public static function activityIndexName(string $table, string $prefix): string
    {
        $name = 'dw_history_run_activity_sequence';

        return $prefix === '' && $table === 'workflow_history_events'
            ? $name
            : $name . '_' . substr(hash('sha256', $prefix . $table), 0, 12);
    }

    /**
     * Capture the typed operation events under the existing run lock.
     *
     * @return Collection<int, WorkflowHistoryEvent>|null
     */
    public static function lockedForStep(WorkflowRun $run, int $step): ?Collection
    {
        if (! self::stepIndexCovers($run)) {
            return null;
        }

        $query = $run->historyEvents()
            ->where('indexed_step_sequence', $step)
            ->where('sequence', '<=', (int) $run->last_history_sequence);
        $model = $query->getRelated();
        $connection = $model->getConnection();
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex(self::stepIndexName($model->getTable(), $connection->getTablePrefix()));
        }

        return $query->lockForUpdate()
            ->get();
    }

    public static function stepIndexCovers(WorkflowRun $run): bool
    {
        return $run->exists && isset($run->step_history_index_through)
            && (int) $run->step_history_index_through === (int) $run->last_history_sequence
            && $run->details_pruned_at === null;
    }

    public static function stepIndexName(string $table, string $prefix): string
    {
        $name = 'dw_history_run_step_sequence';

        return $prefix === '' && $table === 'workflow_history_events'
            ? $name
            : $name . '_' . substr(hash('sha256', $prefix . $table), 0, 12);
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
        $runLockHeld = in_array(
            $eventType,
            [HistoryEventType::ChildWorkflowScheduled, HistoryEventType::ChildRunStarted],
            true
        )
            && WorkflowSignalReadScope::allowsConsistentRead($run, $run->getConnection());

        return self::recordEvent($run, $eventType, $payload, $task, $command, null, $runLockHeld);
    }

    /**
     * @internal The caller holds the run's write lock on the history connection.
     * @param array<string, mixed> $payload
     */
    public static function recordWhileRunLocked(
        WorkflowRun $run,
        HistoryEventType $eventType,
        array $payload,
        ?WorkflowCommand $command = null,
    ): self {
        return self::recordEvent($run, $eventType, $payload, null, $command, null, runLockHeld: true);
    }

    /**
     * Record one child start's consecutive events with one history position
     * read and one run row write (#1286). Each event is stored as record()
     * stores it.
     *
     * @param non-empty-list<array{HistoryEventType, array<string, mixed>}> $events
     * @return non-empty-list<self>
     */
    public static function recordChildStart(
        WorkflowRun $run,
        array $events,
        WorkflowTask|string|null $task = null,
    ): array {
        $runLockHeld = WorkflowSignalReadScope::allowsConsistentRead($run, $run->getConnection());

        return self::appendEvents($run, self::childStartEvents($events, [
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
        ], $task, null), $runLockHeld);
    }

    /**
     * Record a new child run's consecutive start events with one history
     * position read and one run row write (#1286).
     *
     * @internal The caller holds the run's write lock on the history connection.
     * @param non-empty-list<array{HistoryEventType, array<string, mixed>}> $events
     * @return non-empty-list<self>
     */
    public static function recordChildRunStartWhileRunLocked(
        WorkflowRun $run,
        array $events,
        ?WorkflowCommand $command = null,
    ): array {
        return self::appendEvents($run, self::childStartEvents($events, [
            HistoryEventType::StartAccepted,
            HistoryEventType::WorkflowStarted,
        ], null, $command), runLockHeld: true);
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
        bool $runLockHeld = false,
    ): self {
        return self::appendEvents($run, [[$eventType, $payload, $task, $command, $terminalHistory]], $runLockHeld)[0];
    }

    /**
     * Append $events to the run's history in order. One read of the append
     * position and one write of the run row serve the whole list; every event
     * is stored, charged and observed exactly as a separate append stores it.
     *
     * @param non-empty-list<array{
     *     HistoryEventType,
     *     array<string, mixed>,
     *     WorkflowTask|string|null,
     *     WorkflowCommand|string|null,
     *     TerminalCancellationHistory|null
     * }> $events
     * @return non-empty-list<self>
     */
    private static function appendEvents(WorkflowRun $run, array $events, bool $runLockHeld): array
    {
        $prepared = [];
        foreach ($events as [$eventType, $payload, $task, $command, $terminalHistory]) {
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
            HistoryEventPayloadContract::assertKnownPayloadKeys($eventType, $payload);
            $prepared[] = [
                $eventType,
                $payload,
                $taskModel,
                $commandModel,
                $taskModel?->id ?? (is_string($task) ? $task : null),
                $commandModel?->id ?? (is_string($command) ? $command : null),
                $terminalHistory,
            ];
        }

        /** @var non-empty-list<self> $recorded */
        $recorded = ConfiguredV2Models::query('history_event_model', self::class)
            ->getModel()
            ->getConnection()
            ->transaction(static function () use ($run, $prepared, $runLockHeld): array {
                [$lockedRun, $sequence] = self::readAppendPosition($run, $runLockHeld);
                // A metered completion (#930) charges each event as stored,
                // and the run row whose history position it advances.
                $meter = HistoryTransactionMeter::current();
                $appended = [];
                $updates = [];

                foreach ($prepared as $offset => [
                    $eventType,
                    $payload,
                    $taskModel,
                    $commandModel,
                    $taskId,
                    $commandId,
                    $terminalHistory,
                ]) {
                    $sequence += $offset === 0 ? 0 : 1;
                    $now = now();
                    $storedPayload = self::snapshotPayload($payload, $taskModel, $commandModel, $terminalHistory);
                    $meter?->chargeEvent($eventType->value, $storedPayload);
                    $meter?->chargeRows(1);

                    $values = [
                        'workflow_run_id' => $lockedRun->id,
                        'sequence' => $sequence,
                        'event_type' => $eventType->value,
                        'payload' => $storedPayload,
                        'workflow_task_id' => $taskId,
                        'workflow_command_id' => $commandId,
                        'recorded_at' => $now,
                    ];
                    $indexedActivity = false;
                    if (array_key_exists('activity_history_index_through', $lockedRun->getAttributes())) {
                        $indexedActivity = self::activityExecutionIdForIndex($storedPayload, $taskModel, $taskId);
                        $values['indexed_activity_execution_id'] = $indexedActivity === false ? null : $indexedActivity;
                    }
                    $hasStepIndex = array_key_exists('step_history_index_through', $lockedRun->getAttributes());
                    $stepIndexComplete = false;
                    if ($hasStepIndex) {
                        $step = $storedPayload['sequence'] ?? null;
                        $stepIndexComplete = $step === null || (is_int($step) && $step >= 0);
                        $values['indexed_step_sequence'] = $stepIndexComplete ? $step : null;
                    }

                    /** @var self $event */
                    $event = ConfiguredV2Models::query('history_event_model', self::class)->create($values);
                    ExecutionTracing::observeOutcome($lockedRun, $taskModel, $event);

                    $eventUpdates = [
                        'last_history_sequence' => $sequence,
                    ];
                    // An earlier unindexed append leaves a gap that later writers cannot certify.
                    if ($indexedActivity !== false
                        && (int) $lockedRun->activity_history_index_through === $sequence - 1) {
                        $eventUpdates['activity_history_index_through'] = $sequence;
                    }
                    if ($stepIndexComplete && (int) $lockedRun->step_history_index_through === $sequence - 1) {
                        $eventUpdates['step_history_index_through'] = $sequence;
                    }
                    if (OpenActivityHistoryIndex::advance($lockedRun, $event, $indexedActivity)) {
                        $eventUpdates[OpenActivityHistoryIndex::THROUGH] = $sequence;
                    }
                    if ($eventType !== HistoryEventType::ActivityOutcomeRejected) {
                        $eventUpdates['last_progress_at'] = $now;
                    }
                    // The next event's index checks read the advanced position.
                    $lockedRun->forceFill($eventUpdates);
                    $updates = array_merge($updates, $eventUpdates);
                    $appended[] = [$event, $taskModel];
                }

                $lockedRun->save();

                foreach ($appended as [$event, $taskModel]) {
                    PendingSignalCloser::closeForTerminalEvent($lockedRun, $event, $taskModel);
                }

                $run->forceFill($updates);
                $run->syncOriginalAttributes(array_keys($updates));

                foreach ($appended as [$event]) {
                    LinkedServiceCallCloser::closeForTerminalEvent($lockedRun, $event);
                    ServiceCallWorkflowDelivery::forAdmission($lockedRun, $event);
                }

                return array_column($appended, 0);
            });

        return $recorded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string|false|null False leaves coverage behind the history head. A null is an unassociated event.
     */
    private static function activityExecutionIdForIndex(
        array $payload,
        ?WorkflowTask $task,
        ?string $taskId,
    ): string|false|null {
        if ($task === null && $taskId !== null) {
            return false;
        }

        $subjects = array_values(array_unique(array_filter([
            $payload['activity_execution_id'] ?? null,
            $payload['activity']['id'] ?? null,
            $payload['activity_attempt']['activity_execution_id'] ?? null,
            $task?->payload['activity_execution_id'] ?? null,
        ], static fn (mixed $id): bool => is_string($id) && $id !== '')));

        $activityId = $subjects[0] ?? null;
        return count($subjects) > 1 || ($activityId !== null && strlen($activityId) > 26)
            ? false : $activityId;
    }

    /**
     * Only events whose append has no terminal, update or service-call side
     * effects may share one append; those effects run after the whole list.
     *
     * @param non-empty-list<array{HistoryEventType, array<string, mixed>}> $events
     * @param list<HistoryEventType> $allowed
     * @return non-empty-list<array{
     *     HistoryEventType,
     *     array<string, mixed>,
     *     WorkflowTask|string|null,
     *     WorkflowCommand|string|null,
     *     null
     * }>
     */
    private static function childStartEvents(
        array $events,
        array $allowed,
        WorkflowTask|string|null $task,
        ?WorkflowCommand $command,
    ): array {
        $prepared = [];
        foreach ($events as [$eventType, $payload]) {
            if (! in_array($eventType, $allowed, true)) {
                throw new \LogicException(sprintf(
                    'History event [%s] cannot share a child start append.',
                    $eventType->value
                ));
            }
            $prepared[] = [$eventType, $payload, $task, $command, null];
        }

        if ($prepared === []) {
            throw new \LogicException('A child start append needs at least one event.');
        }

        return $prepared;
    }

    /**
     * @return array{WorkflowRun, int}
     */
    private static function readAppendPosition(WorkflowRun $run, bool $runLockHeld): array
    {
        $runs = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate();
        $history = ConfiguredV2Models::query('history_event_model', self::class)
            ->where('workflow_run_id', $run->id);
        $runModel = $runs->getModel();
        $historyModel = $history->getModel();
        $connection = $historyModel->getConnection();

        if ($runLockHeld && $runModel::class === WorkflowRun::class && $historyModel::class === self::class
            && $runModel->getConnection() === $connection && $run->getConnection() === $connection
            && $connection->transactionLevel() > 1
            && $runModel->getGlobalScopes() === [] && $historyModel->getGlobalScopes() === []) {
            // The lock was acquired before this statement, so the history
            // maximum cannot be evaluated ahead of waiting for another writer.
            $row = (clone $runs)->whereKey($run->id)
                ->select($runModel->qualifyColumn('*'))
                ->selectSub((clone $history)->selectRaw('MAX(sequence)'), 'stored_history_head')
                ->toBase()
                ->first();
            if ($row !== null) {
                $attributes = (array) $row;
                $storedMax = (int) $attributes['stored_history_head'];
                unset($attributes['stored_history_head']);
                $lockedRun = $runModel->newFromBuilder($attributes);

                return [$lockedRun, max((int) $lockedRun->last_history_sequence, $storedMax) + 1];
            }
        }

        /** @var WorkflowRun $lockedRun */
        $lockedRun = $runs->findOrFail($run->id);
        $storedMax = (int) $history->max('sequence');

        return [$lockedRun, max((int) $lockedRun->last_history_sequence, $storedMax) + 1];
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
