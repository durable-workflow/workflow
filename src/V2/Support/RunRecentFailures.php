<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunRecentFailures
{
    private const MAX_COMPLETE_MESSAGE_STORAGE_BYTES = 90_000;

    /**
     * Read recent diagnostic rows and locate their retained supporting events.
     * This is not a complete failure/handled-state audit of durable history.
     *
     * @return array<string, mixed>
     */
    public static function forRun(WorkflowRun $run, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Recent failure limits must be from 1 to 50.');
        }

        /** @var class-string<WorkflowFailure> $failureModel */
        $failureModel = ConfiguredV2Models::resolve('failure_model', WorkflowFailure::class);
        $model = (new $failureModel())->setConnection($run->getConnectionName());
        $connection = $model->getConnection();
        $full = $connection->getQueryGrammar()->wrap('message_full');
        $bytes = match ($connection->getDriverName()) {
            'sqlite' => "LENGTH(CAST({$full} AS BLOB))",
            'sqlsrv' => "DATALENGTH({$full})",
            default => "OCTET_LENGTH({$full})",
        };
        $storageBudget = self::MAX_COMPLETE_MESSAGE_STORAGE_BYTES
            * ($connection->getDriverName() === 'sqlsrv' ? 2 : 1);
        $rows = $model
            ->newQuery()
            ->setEagerLoads([])
            ->where('workflow_run_id', $run->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->select([
                'id', 'workflow_run_id', 'source_kind', 'source_id', 'propagation_kind',
                'failure_category', 'non_retryable', 'handled', 'exception_class',
                'message', 'file', 'line', 'trace_preview', 'created_at',
            ])
            // Large canonical messages belong to detail/history reads. Keep
            // this diagnostic list on the labelled compatibility preview
            // without transferring every full payload into PHP first.
            ->selectRaw("CASE WHEN {$bytes} <= ? THEN {$full} ELSE NULL END AS {$full}", [$storageBudget])
            ->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        /** @var class-string<WorkflowHistoryEvent> $eventModel */
        $eventModel = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);
        $query = (new $eventModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])
            ->where('workflow_run_id', $run->id);
        // Aggregate only selected identities. Successful updates and later
        // FailureHandled events must never become the primary failure link.
        $references = (clone $query)->whereIn('payload->failure_id', $rows->modelKeys())
            ->whereIn('event_type', [
                HistoryEventType::ActivityFailed->value,
                HistoryEventType::ActivityTimedOut->value,
                HistoryEventType::ChildRunFailed->value,
                HistoryEventType::ChildRunCancelled->value,
                HistoryEventType::ChildRunTerminated->value,
                HistoryEventType::WorkflowFailed->value,
                HistoryEventType::WorkflowTimedOut->value,
                HistoryEventType::WorkflowCancelled->value,
                HistoryEventType::WorkflowTerminated->value,
                HistoryEventType::UpdateCompleted->value,
            ])->toBase()
            ->select('payload->failure_id as failure_id')
            ->selectRaw('MAX(sequence) as sequence')
            ->groupBy('payload->failure_id')
            ->get();
        $failureBySequence = $references->pluck('failure_id', 'sequence');
        $events = (clone $query)->whereIn('sequence', $failureBySequence->keys()->all())
            ->get(['id', 'workflow_run_id', 'sequence', 'event_type', 'recorded_at'])
            ->keyBy(
                static fn (WorkflowHistoryEvent $event): string => (string) $failureBySequence->get($event->sequence)
            );
        $failures = $rows->map(static function (WorkflowFailure $failure) use ($events, $run): array {
            $event = $events->get($failure->id);

            return [
                'id' => $failure->id,
                'source_kind' => $failure->source_kind,
                'source_id' => $failure->source_id,
                'propagation_kind' => $failure->propagation_kind,
                'failure_category' => $failure->failure_category?->value,
                'non_retryable' => (bool) $failure->non_retryable,
                'handled' => (bool) $failure->handled,
                'handled_source' => 'workflow_failures',
                'exception_class' => $failure->exception_class,
                'message' => $failure->message,
                'file' => $failure->file,
                'line' => $failure->line,
                'trace_preview' => $failure->trace_preview,
                'created_at' => $failure->created_at?->toIso8601String(),
                'event_sequence' => $event?->sequence,
                'supporting_event' => $event instanceof WorkflowHistoryEvent ? [
                    'state' => 'retained',
                    'id' => $event->id,
                    'sequence' => (int) $event->sequence,
                    'event_type' => $event->event_type->value,
                    'recorded_at' => $event->recorded_at?->toIso8601String(),
                    'after_sequence' => max(0, (int) $event->sequence - 1),
                ] : [
                    'state' => $run->details_pruned_at === null ? 'unavailable' : 'pruned',
                ],
            ];
        })->values()
            ->all();

        return [
            'instance_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $run->namespace,
            'source' => 'workflow_failures',
            'history_audit' => 'not_evaluated',
            'state' => $run->details_pruned_at === null ? 'partial' : 'pruned',
            'failures' => $failures,
            'returned_count' => count($failures),
            'total_count' => null,
            'limit' => $limit,
            'has_more' => $hasMore,
        ];
    }
}
