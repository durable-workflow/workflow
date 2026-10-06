<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class RunObservation
{
    /**
     * An initial operator observation, independent of history size, run count
     * and child history size. Current identity is an observed stored pointer,
     * not canonical lineage authority for issuing commands or repairing runs.
     *
     * @return array<string, mixed>
     */
    public static function forRun(WorkflowRun $run, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        /** @var class-string<WorkflowRunSummary> $summaryModel */
        $summaryModel = ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class);
        $summary = (new $summaryModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])->whereKey($run->id)
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('namespace', $run->namespace)
            ->first([
                'id', 'duration_ms', 'exception_count', 'history_event_count',
                'history_size_bytes', 'history_fan_out', 'wait_kind', 'wait_reason',
                'wait_started_at', 'wait_deadline_at', 'liveness_state', 'liveness_reason',
                'task_problem', 'updated_at',
            ]);
        /** @var class-string<WorkflowInstance> $instanceModel */
        $instanceModel = ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class);
        $instance = (new $instanceModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])->whereKey($run->workflow_instance_id)
            ->where('namespace', $run->namespace)
            ->first(['id', 'current_run_id']);
        /** @var class-string<WorkflowRun> $runModel */
        $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $current = $instance?->current_run_id === null ? null
            : (new $runModel())->setConnection($run->getConnectionName())
                ->newQuery()
                ->setEagerLoads([])->whereKey($instance->current_run_id)
                ->where('workflow_instance_id', $run->workflow_instance_id)
                ->where('namespace', $run->namespace)
                ->first(['id', 'status', 'closed_reason']);
        /** @var class-string<WorkflowHistoryEvent> $eventModel */
        $eventModel = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);
        $started = (new $eventModel())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->orderBy('sequence')
            ->first(['id', 'workflow_run_id', 'sequence', 'event_type', 'payload']);
        // These helpers need only WorkflowStarted. Keep its partial relation on
        // a private clone so canonical readers never mistake it for all history.
        $contractRun = clone $run;
        $contractRun->setRelations([]);
        $contractRun->setRelation('historyEvents', $run->newCollection($started === null ? [] : [$started]));

        return [
            'id' => $run->id,
            'instance_id' => $run->workflow_instance_id,
            'selected_run_id' => $run->id,
            'run_id' => $run->id,
            'namespace' => $run->namespace,
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'run_number' => (int) $run->run_number,
            'status' => $run->status->value,
            'is_terminal' => $run->status->isTerminal(),
            'closed_reason' => $run->closed_reason,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels ?? [],
            'compatibility' => $run->compatibility,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'started_at' => $run->started_at?->toIso8601String(),
            'closed_at' => $run->closed_at?->toIso8601String(),
            'archived_at' => $run->archived_at?->toIso8601String(),
            'details_pruned_at' => $run->details_pruned_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'execution_deadline_at' => $run->execution_deadline_at?->toIso8601String(),
            'run_deadline_at' => $run->run_deadline_at?->toIso8601String(),
            'current_run_id' => $current?->id,
            'current_run_status' => $current?->status?->value,
            'is_current_run' => $current === null ? null : $current->id === $run->id,
            'current_run_source' => 'workflow_instances.current_run_id',
            'current_run_state' => $current === null ? 'unavailable' : 'observed',
            'current_run_audit' => 'not_evaluated',
            'observed_at' => $now->toIso8601String(),
            'history_audit' => 'not_evaluated',
            'summary_state' => $summary === null ? 'unavailable' : 'observed',
            'summary_observed_at' => $summary?->updated_at?->toIso8601String(),
            'duration_ms' => $summary?->duration_ms,
            'exception_count' => $summary?->exception_count,
            'history_event_count' => $summary?->history_event_count,
            'history_size_bytes' => $summary?->history_size_bytes,
            'history_fan_out' => $summary?->history_fan_out,
            'liveness_state' => $summary?->liveness_state,
            'liveness_reason' => $summary?->liveness_reason,
            'command_contract' => RunCommandContract::forRun($contractRun),
            'workflow_definition_fingerprint' => WorkflowDefinitionFingerprint::recordedForRun($contractRun),
            'current_waits' => RunCurrentWaits::forRun($run, now: $now),
            'parents' => RunRelationshipsPage::forRun($run, direction: 'parents'),
            'children' => RunRelationshipsPage::forRun($run),
            'recent_failures' => RunRecentFailures::forRun($run),
        ];
    }
}
