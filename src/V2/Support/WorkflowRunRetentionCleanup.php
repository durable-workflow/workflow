<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * Deletes retained run detail rows after a host has exported or expired a
 * terminal run.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class WorkflowRunRetentionCleanup
{
    /**
     * @return array{
     *     activity_attempts_deleted: int,
     *     activity_executions_deleted: int,
     *     child_calls_deleted: int,
     *     commands_deleted: int,
     *     failures_deleted: int,
     *     history_events_deleted: int,
     *     links_deleted: int,
     *     memos_deleted: int,
     *     messages_deleted: int,
     *     run_deleted: int,
     *     run_lineage_entries_deleted: int,
     *     run_summary_deleted: int,
     *     run_timer_entries_deleted: int,
     *     run_waits_deleted: int,
     *     search_attributes_deleted: int,
     *     signals_deleted: int,
     *     tasks_deleted: int,
     *     timers_deleted: int,
     *     timeline_entries_deleted: int,
     *     updates_deleted: int
     * }
     */
    public static function pruneRun(WorkflowRun|string $run): array
    {
        $runId = $run instanceof WorkflowRun ? (string) $run->getKey() : $run;

        return DB::transaction(static function () use ($runId): array {
            /** @var WorkflowRun $lockedRun */
            $lockedRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            self::assertPrunable($lockedRun);

            $report = self::emptyReport();

            $report['activity_attempts_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('activity_attempt_model', ActivityAttempt::class),
                $runId,
            );
            $report['updates_deleted'] = self::deleteByAnyRunReference(
                ConfiguredV2Models::query('update_model', WorkflowUpdate::class),
                $runId,
            );
            $report['signals_deleted'] = self::deleteByAnyRunReference(
                ConfiguredV2Models::query('signal_model', WorkflowSignal::class),
                $runId,
            );
            $report['messages_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('message_model', WorkflowMessage::class),
                $runId,
            );
            $report['child_calls_deleted'] = self::deleteByColumn(
                ConfiguredV2Models::query('child_call_model', WorkflowChildCall::class),
                'parent_workflow_run_id',
                $runId,
            );
            $report['links_deleted'] = self::deleteLinks($runId);
            $report['activity_executions_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('activity_execution_model', ActivityExecution::class),
                $runId,
            );
            $report['commands_deleted'] = self::deleteByAnyRunReference(
                ConfiguredV2Models::query('command_model', WorkflowCommand::class),
                $runId,
            );
            $report['failures_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('failure_model', WorkflowFailure::class),
                $runId,
            );
            $report['run_waits_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('run_wait_model', WorkflowRunWait::class),
                $runId,
            );
            $report['timeline_entries_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('run_timeline_entry_model', WorkflowTimelineEntry::class),
                $runId,
            );
            $report['run_timer_entries_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('run_timer_entry_model', WorkflowRunTimerEntry::class),
                $runId,
            );
            $report['run_lineage_entries_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('run_lineage_entry_model', WorkflowRunLineageEntry::class),
                $runId,
            );
            $report['timers_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('timer_model', WorkflowTimer::class),
                $runId,
            );
            $report['memos_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('memo_model', WorkflowMemo::class),
                $runId,
            );
            $report['search_attributes_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('search_attribute_model', WorkflowSearchAttribute::class),
                $runId,
            );
            $report['history_events_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class),
                $runId,
            );
            $report['tasks_deleted'] = self::deleteByRun(
                ConfiguredV2Models::query('task_model', WorkflowTask::class),
                $runId,
            );
            $report['run_summary_deleted'] = ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)
                ->whereKey($runId)
                ->delete();

            return $report;
        });
    }

    private static function assertPrunable(WorkflowRun $run): void
    {
        $status = $run->status instanceof RunStatus
            ? $run->status
            : (is_string($run->status) ? RunStatus::tryFrom($run->status) : null);

        if ($status === null || ! $status->isTerminal()) {
            throw new LogicException(sprintf('Workflow run [%s] is not terminal.', $run->getKey()));
        }

        if ($run->closed_at === null) {
            throw new LogicException(sprintf('Workflow run [%s] has not been closed.', $run->getKey()));
        }
    }

    /**
     * @return array{
     *     activity_attempts_deleted: int,
     *     activity_executions_deleted: int,
     *     child_calls_deleted: int,
     *     commands_deleted: int,
     *     failures_deleted: int,
     *     history_events_deleted: int,
     *     links_deleted: int,
     *     memos_deleted: int,
     *     messages_deleted: int,
     *     run_deleted: int,
     *     run_lineage_entries_deleted: int,
     *     run_summary_deleted: int,
     *     run_timer_entries_deleted: int,
     *     run_waits_deleted: int,
     *     search_attributes_deleted: int,
     *     signals_deleted: int,
     *     tasks_deleted: int,
     *     timers_deleted: int,
     *     timeline_entries_deleted: int,
     *     updates_deleted: int
     * }
     */
    private static function emptyReport(): array
    {
        return [
            'activity_attempts_deleted' => 0,
            'activity_executions_deleted' => 0,
            'child_calls_deleted' => 0,
            'commands_deleted' => 0,
            'failures_deleted' => 0,
            'history_events_deleted' => 0,
            'links_deleted' => 0,
            'memos_deleted' => 0,
            'messages_deleted' => 0,
            'run_deleted' => 0,
            'run_lineage_entries_deleted' => 0,
            'run_summary_deleted' => 0,
            'run_timer_entries_deleted' => 0,
            'run_waits_deleted' => 0,
            'search_attributes_deleted' => 0,
            'signals_deleted' => 0,
            'tasks_deleted' => 0,
            'timers_deleted' => 0,
            'timeline_entries_deleted' => 0,
            'updates_deleted' => 0,
        ];
    }

    /**
     * @param Builder<Model> $query
     */
    private static function deleteByRun(Builder $query, string $runId): int
    {
        return self::deleteByColumn($query, 'workflow_run_id', $runId);
    }

    /**
     * @param Builder<Model> $query
     */
    private static function deleteByColumn(Builder $query, string $column, string $runId): int
    {
        return $query
            ->where($column, $runId)
            ->delete();
    }

    /**
     * @param Builder<Model> $query
     */
    private static function deleteByAnyRunReference(Builder $query, string $runId): int
    {
        return $query
            ->where(static function (Builder $query) use ($runId): void {
                $query
                    ->where('workflow_run_id', $runId)
                    ->orWhere('requested_workflow_run_id', $runId)
                    ->orWhere('resolved_workflow_run_id', $runId);
            })
            ->delete();
    }

    private static function deleteLinks(string $runId): int
    {
        return ConfiguredV2Models::query('link_model', WorkflowLink::class)
            ->where(static function (Builder $query) use ($runId): void {
                $query
                    ->where('parent_workflow_run_id', $runId)
                    ->orWhere('child_workflow_run_id', $runId);
            })
            ->delete();
    }
}
