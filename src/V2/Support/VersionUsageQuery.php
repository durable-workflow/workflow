<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/** Read-only, namespace-bound observations of retained open-run version decisions. */
final class VersionUsageQuery
{
    /**
     * Frozen v1 retained format; enum growth does not silently expand reader support.
     */
    private const EVENT_TYPES = [
        'StartAccepted',
        'StartRejected',
        'WorkflowStarted',
        'WorkflowContinuedAsNew',
        'ChildWorkflowScheduled',
        'ChildWorkflowAwaited',
        'ChildCancellationRequested',
        'ChildCancellationResolved',
        'ChildRunStarted',
        'ChildRunCompleted',
        'ChildRunFailed',
        'ChildRunCancelled',
        'ChildRunTerminated',
        'ServiceOperationScheduled',
        'ServiceCallStarted',
        'ServiceCallCompleted',
        'ServiceCallFailed',
        'ServiceCallCancelled',
        'ConditionWaitOpened',
        'ConditionWaitSatisfied',
        'ConditionWaitTimedOut',
        'ConditionWaitCancelled',
        'SignalWaitOpened',
        'SignalWaitCancelled',
        'SignalReceived',
        'SignalApplied',
        'SignalRejected',
        'UpdateAccepted',
        'UpdateRejected',
        'UpdateApplied',
        'UpdateCompleted',
        'RepairRequested',
        'CancelRequested',
        'CooperativeCancellationRequested',
        'CooperativeCancellationDelivered',
        'CancellationScopeOpened',
        'CancellationScopeRequested',
        'CancellationScopeDeliveryPrepared',
        'CancellationScopeDelivered',
        'CancellationScopeRequestConflicted',
        'WorkflowCancelled',
        'TerminateRequested',
        'WorkflowTerminated',
        'ArchiveRequested',
        'WorkflowArchived',
        'ActivityScheduled',
        'ActivityStarted',
        'ActivityHeartbeatRecorded',
        'ActivityRetryScheduled',
        'ActivityCompleted',
        'ActivityFailed',
        'ActivityOutcomeRejected',
        'ActivityCancelled',
        'ActivityCancellationAcknowledged',
        'ActivityTimedOut',
        'FailureHandled',
        'SideEffectRecorded',
        'VersionMarkerRecorded',
        'TimerScheduled',
        'TimerFired',
        'TimerCancelled',
        'SelectionResolved',
        'SelectionOperationCancelled',
        'SearchAttributesUpserted',
        'MemoUpserted',
        'WorkflowTimedOut',
        'WorkflowTimeoutCleanupStarted',
        'WorkflowCompleted',
        'WorkflowFailed',
        'ParentClosePolicyApplied',
        'ParentClosePolicyFailed',
        'ParentCloseCancellationRequested',
        'MessageCursorAdvanced',
        'ScheduleCreated',
        'SchedulePaused',
        'ScheduleResumed',
        'ScheduleUpdated',
        'ScheduleTriggered',
        'ScheduleDeleted',
        'ScheduleTriggerSkipped',
    ];

    public function page(
        ?string $namespace,
        string $workflowType,
        string $changeId,
        int $minVersion,
        int $limit = 50,
        ?string $cursor = null,
        ?string $state = null,
    ): array {
        self::text($workflowType, 255);
        self::text($changeId, 1024);
        if ($namespace !== null) {
            self::text($namespace, 255);
        }
        if (! VersionUsageDecision::integer($minVersion) || $limit < 1 || $limit > 200
            || ! in_array($state, [null, 'legacy', 'current', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid version-usage threshold, state or page limit.');
        }
        $identity = hash('sha256', json_encode([
            $namespace, $workflowType, $changeId, $minVersion, $state,
        ], JSON_THROW_ON_ERROR));
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('A non-empty application key is required for version-usage cursors.');
        }
        $continuation = $cursor === null ? null : VersionUsageCursor::decode($cursor, $identity, $key);
        $base = $this->runs($namespace, $workflowType);
        $idExpression = $this->idExpression($base);
        $through = $continuation['through'] ?? (clone $base)->orderByRaw("{$idExpression} DESC")->value('id');
        $after = $continuation['after'] ?? null;
        $counts = [
            'total' => 0,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 0,
        ];
        $results = [];
        if ($through !== null) {
            $model = $base->getModel();
            foreach ($this->batches($base, $through) as $runs) {
                $decisions = $this->decisions($runs, $model, $namespace, $changeId, $minVersion);
                foreach ($runs as $run) {
                    $decision = $decisions[$run->id]->result();
                    ++$counts['total'];
                    ++$counts[$decision['state']];
                    if (($state === null || $decision['state'] === $state)
                        && ($after === null || strcmp($run->id, $after) > 0)
                        && count($results) <= $limit) {
                        $results[] = [
                            'instance_id' => $run->workflow_instance_id,
                            'run_id' => $run->id,
                            'status' => $run->status,
                        ] + $decision;
                    }
                }
            }
        }
        $hasMore = count($results) > $limit;
        $results = array_slice($results, 0, $limit);
        return [
            'schema_version' => 1,
            'namespace' => $namespace,
            'workflow_type' => $workflowType,
            'change_id' => $changeId,
            'min_version' => $minVersion,
            'state' => $state,
            'consistency' => 'live',
            'through_run_id' => $through,
            'counts' => $counts,
            'runs' => $results,
            'limit' => $limit,
            'returned_count' => count($results),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? VersionUsageCursor::encode(
                $identity,
                $through,
                $results[array_key_last($results)]['run_id'],
                $key
            ) : null,
        ];
    }

    /**
     * @return Generator<int, Collection>
     */
    private function batches(Builder $base, string $through): Generator
    {
        $id = $this->idExpression($base);
        $parameter = self::binary('?', $base->getQuery()->getConnection()->getDriverName());
        $query = (clone $base)->select([
            'id', 'workflow_instance_id', 'status', 'details_pruned_at', 'last_history_sequence', 'import_source',
        ])->whereRaw("{$id} <= {$parameter}", [$through])->toBase();
        yield from $this->rows($query, 100);
    }

    /**
     * @return Generator<int, Collection>
     */
    private function rows(QueryBuilder $base, int $size): Generator
    {
        $driver = $base->getConnection()
            ->getDriverName();
        $id = self::binary($base->getGrammar()->wrap('id'), $driver);
        $parameter = self::binary('?', $driver);
        $after = null;
        do {
            $query = (clone $base)->orderByRaw("{$id} ASC");
            if ($after !== null) {
                $query->whereRaw("{$id} > {$parameter}", [$after]);
            }
            $rows = $query->limit($size)
                ->get();
            if ($rows->isEmpty()) {
                return;
            }
            yield $rows;
            $after = $rows->last()
->id;
        } while ($rows->count() === $size);
    }

    private function identities(QueryBuilder $query, string $column, array $values): QueryBuilder
    {
        $query->whereIn($column, $values);
        if ($values === []) {
            return $query;
        }
        $driver = $query->getConnection()
            ->getDriverName();
        $field = self::binary($query->getGrammar()->wrap($column), $driver);
        $parameters = implode(', ', array_fill(0, count($values), self::binary('?', $driver)));
        return $query->whereRaw("{$field} IN ({$parameters})", $values);
    }

    private function idExpression(Builder $query): string
    {
        return self::binary(
            $query->getQuery()
                ->getGrammar()
                ->wrap($query->getModel()->qualifyColumn('id')),
            $query->getQuery()
                ->getConnection()
                ->getDriverName(),
        );
    }

    /**
     * @return Builder<WorkflowRun>
     */
    private function runs(?string $namespace, string $workflowType): Builder
    {
        $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $query = NamespaceIdentity::constrain($model::query()->setEagerLoads([]), $namespace)
            ->where('workflow_type', $workflowType)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value, RunStatus::Waiting->value]);
        $column = $query->getQuery()
            ->getGrammar()
            ->wrap($query->getModel()->qualifyColumn('workflow_type'));
        $driver = $query->getQuery()
            ->getConnection()
            ->getDriverName();
        $column = self::binary($column, $driver);
        $parameter = self::binary('?', $driver);
        $query->whereRaw("{$column} = {$parameter}", [$workflowType]);
        $status = $query->getQuery()
            ->getGrammar()
            ->wrap($query->getModel()->qualifyColumn('status'));
        $parameters = implode(', ', array_fill(0, 3, self::binary('?', $driver)));
        $status = self::binary($status, $driver);
        $query->whereRaw("{$status} IN ({$parameters})", [
            RunStatus::Pending->value, RunStatus::Running->value, RunStatus::Waiting->value,
        ]);
        return $query;
    }

    /**
     * @return array<string, VersionUsageDecision>
     */
    private function decisions(
        Collection $runs,
        WorkflowRun $runModel,
        ?string $namespace,
        string $changeId,
        int $minVersion
    ): array {
        $instanceModel = ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class);
        $owners = $this->identities(NamespaceIdentity::constrain(
            (new $instanceModel())->setConnection($runModel->getConnectionName())
                ->newQuery()
                ->setEagerLoads([]),
            $namespace,
        )->toBase(), 'id', $runs->pluck('workflow_instance_id')
            ->all())->pluck('id')
            ->all();
        $eventModel = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);
        $history = $this->identities(
            (new $eventModel())->setConnection($runModel->getConnectionName())
                ->newQuery()
                ->setEagerLoads([])->toBase(),
            'workflow_run_id',
            $runs->pluck('id')
                ->all(),
        );
        $driver = $history->getConnection()
            ->getDriverName();
        $sequence = $history->getGrammar()
            ->wrap('sequence');
        $kind = self::binary($history->getGrammar()->wrap('event_type'), $driver);
        $marker = self::binary('?', $driver);
        $runId = self::binary($history->getGrammar()->wrap('workflow_run_id'), $driver);
        $statistics = (clone $history)->selectRaw(
            "{$runId} AS workflow_run_id, COUNT(*) AS event_count, MIN({$sequence}) AS first_sequence, MAX({$sequence}) AS last_sequence, SUM(CASE WHEN {$kind} = {$marker} THEN 1 ELSE 0 END) AS marker_count, SUM(CASE WHEN {$kind} = {$marker} THEN 1 ELSE 0 END) AS started_count, SUM(CASE WHEN {$kind} = {$marker} THEN 1 ELSE 0 END) AS accepted_count",
            [HistoryEventType::VersionMarkerRecorded->value, HistoryEventType::WorkflowStarted->value,
                HistoryEventType::StartAccepted->value],
        )->groupByRaw($runId)
            ->get()
            ->keyBy('workflow_run_id');
        $starts = (clone $history)->where('sequence', 1)
            ->pluck('event_type', 'workflow_run_id');
        $seconds = (clone $history)->where('sequence', 2)
            ->pluck('event_type', 'workflow_run_id');
        $types = self::EVENT_TYPES;
        $driver = $history->getConnection()
            ->getDriverName();
        $kind = self::binary($history->getGrammar()->wrap('event_type'), $driver);
        $parameters = implode(', ', array_fill(0, count($types), self::binary('?', $driver)));
        $unsupported = (clone $history)->whereRaw("{$kind} NOT IN ({$parameters})", $types)
            ->selectRaw("{$runId} AS workflow_run_id")
            ->distinct()
            ->get()
            ->pluck('workflow_run_id')
            ->all();
        $startedType = HistoryEventType::WorkflowStarted->value;
        $decisions = [];
        $inspect = [];
        foreach ($runs as $run) {
            $stats = $statistics->get($run->id);
            $available = $run->details_pruned_at === null && $stats !== null
                && in_array($run->workflow_instance_id, $owners, true)
                && in_array($run->import_source, [null, EmbeddedV2ImportContract::IMPORT_SOURCE], true)
                && (int) $stats->first_sequence === 1
                && (int) $stats->last_sequence === (int) $run->last_history_sequence
                && (int) $stats->event_count === (int) $run->last_history_sequence
                && (int) $stats->marker_count <= 1000
                && (int) $stats->started_count === 1
                && (($starts->get($run->id) === $startedType && (int) $stats->accepted_count === 0)
                    || ($starts->get($run->id) === HistoryEventType::StartAccepted->value
                        && $seconds->get($run->id) === $startedType && (int) $stats->accepted_count === 1))
                && ! in_array($run->id, $unsupported, true);
            $decisions[$run->id] = new VersionUsageDecision($changeId, $minVersion, $available);
            if ($available) {
                $inspect[] = $run->id;
            }
        }
        $markers = $this->identities(clone $history, 'workflow_run_id', $inspect)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->select(['id', 'workflow_run_id', 'event_type', 'payload']);
        foreach ($this->rows($markers, 250) as $events) {
            foreach ($events as $event) {
                if ($event->event_type !== HistoryEventType::VersionMarkerRecorded->value) {
                    $decisions[$event->workflow_run_id]->unavailable();
                    continue;
                }
                try {
                    $payload = is_string($event->payload)
                        ? json_decode($event->payload, true, 32, JSON_THROW_ON_ERROR) : $event->payload;
                } catch (JsonException) {
                    $payload = null;
                }
                $decisions[$event->workflow_run_id]->observe($payload);
            }
        }
        // A prune or append racing this observation cannot turn a partial read into current usage.
        $latest = $this->identities(
            NamespaceIdentity::constrain($runModel->newQuery()->setEagerLoads([]), $namespace)->toBase(),
            'id',
            $runs->pluck('id')
                ->all(),
        )->get(['id', 'last_history_sequence', 'details_pruned_at'])->keyBy('id');
        foreach ($runs as $run) {
            $end = $latest->get($run->id);
            if ($end === null || $end->details_pruned_at !== null
                || (int) $end->last_history_sequence !== (int) $run->last_history_sequence) {
                $decisions[$run->id]->unavailable();
            }
        }
        return $decisions;
    }

    private static function binary(string $expression, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "CAST({$expression} AS BINARY)",
            'sqlite' => "CAST({$expression} AS BLOB)",
            'pgsql' => "CAST({$expression} AS TEXT) COLLATE \"C\"",
            'sqlsrv' => "CONVERT(varbinary(max), CAST({$expression} AS nvarchar(max)))",
            default => throw new InvalidArgumentException('Unsupported storage driver for exact version usage.'),
        };
    }

    private static function text(string $value, int $limit): void
    {
        if ($value === '' || strlen($value) > $limit || preg_match('/(*UCP)\S/u', $value) !== 1) {
            throw new InvalidArgumentException('Version-usage identities must be nonempty bounded UTF-8 strings.');
        }
    }
}
