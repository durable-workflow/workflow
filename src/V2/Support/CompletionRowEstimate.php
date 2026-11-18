<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;

/**
 * The rows a workflow task completion's commands will write, computed from
 * the command list before the completion's first write.
 *
 * Each command family is charged what one command of that family writes:
 * its history events with their run positions, its own rows (task,
 * execution, timer, link, child run and the projections they touch), and
 * the memo and search attribute rows it carries or a child inherits. The
 * figures are measured per family by CompletionRowBudgetDerivationTest,
 * which also checks that every first-party family is covered and that the
 * estimate is never below what the meter charges for that family. A command
 * family without a measured figure is charged {@see self::OTHER_COMMAND_ROWS}.
 *
 * Rows that depend on stored state rather than on the commands (pending
 * updates closed by a terminal command, signals transferred by
 * continue-as-new, parent-close cascades) are not in the estimate; the
 * history transaction meter charges them exactly before each statement.
 */
final class CompletionRowEstimate
{
    /**
     * Completing the task, updating the run and its summary projection.
     */
    public const COMPLETION_ROWS = 3;

    public const COMMAND_ROWS = [
        'schedule_activity' => 6,
        'start_timer' => 7,
        'start_child_workflow' => 19,
        'record_side_effect' => 3,
        'record_version_marker' => 3,
        'complete_workflow' => 3,
        'fail_workflow' => 4,
        'continue_as_new' => 14,
    ];

    /**
     * An upsert_memo or upsert_search_attributes command writes its event, the
     * run position and the run summary, plus one row per entry.
     */
    public const METADATA_UPSERT_ROWS = 3;

    /**
     * Beyond its executions, attempts and failure, a local activity writes
     * three rows per history event (the event, the run position and its
     * timeline entry) and one projection row.
     */
    public const LOCAL_ACTIVITY_ROWS_PER_EVENT = 3;

    public const LOCAL_ACTIVITY_PROJECTION_ROWS = 1;

    /**
     * Any other command: well above every measured first-party family that
     * carries no memo or search attributes.
     */
    public const OTHER_COMMAND_ROWS = 25;

    /**
     * The most rows one history event can bring with it: an upsert of every
     * memo (or search attribute) a run may hold records one event.
     */
    public const MAX_ROWS_PER_HISTORY_EVENT = WorkflowMemo::MAX_MEMOS_PER_RUN + self::METADATA_UPSERT_ROWS;

    /**
     * @param list<mixed> $commands
     */
    public static function rows(WorkflowRun $run, array $commands): int
    {
        $rows = self::COMPLETION_ROWS;
        $inherited = null;
        // Keys written earlier in this completion: a later delete of one
        // removes a row even though the run does not hold it yet.
        $writtenMemos = [];
        $writtenAttributes = [];
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }
            $type = $command['type'] ?? null;
            $cost = match ($type) {
                'record_local_activity' => self::localActivityRows($command),
                'upsert_memo' => self::METADATA_UPSERT_ROWS + self::memoRows($run, $command, $writtenMemos),
                'upsert_search_attributes' => self::METADATA_UPSERT_ROWS
                    + self::searchAttributeRows($run, $command, $writtenAttributes),
                // A child and a continued run inherit the run's memos and
                // search attributes: one row each.
                'start_child_workflow', 'continue_as_new' => self::COMMAND_ROWS[$type]
                    + ($inherited ??= self::inheritedMetadataRows($run)),
                // A service operation may carry memo and search attributes for
                // the run it starts: one row per entry.
                'start_service_operation' => self::OTHER_COMMAND_ROWS
                    + (is_array($command['memo'] ?? null) ? count($command['memo']) : 0)
                    + (is_array($command['search_attributes'] ?? null) ? count($command['search_attributes']) : 0),
                default => is_string($type) && isset(self::COMMAND_ROWS[$type])
                    ? self::COMMAND_ROWS[$type]
                    : self::OTHER_COMMAND_ROWS,
            };
            $rows = HistoryTransactionMeter::checkedAdd($rows, $cost);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function localActivityRows(array $command): int
    {
        $events = WorkflowCommandNormalizer::localActivityHistoryEvents($command);

        return HistoryTransactionMeter::checkedAdd(
            WorkflowCommandNormalizer::localActivityRows($command)
                + self::LOCAL_ACTIVITY_PROJECTION_ROWS,
            HistoryTransactionMeter::checkedMultiply($events, self::LOCAL_ACTIVITY_ROWS_PER_EVENT),
        );
    }

    /**
     * One row per memo the upsert writes, and per memo it deletes that
     * exists: deleting an absent key writes nothing.
     *
     * @param array<string, mixed> $command
     */
    private static function memoRows(WorkflowRun $run, array $command, array &$written): int
    {
        $entries = $command['entries'] ?? null;
        if (! is_array($entries)) {
            return WorkflowMemo::MAX_MEMOS_PER_RUN;
        }
        try {
            $decoded = MemoPayload::decodeEntries($entries);
        } catch (Throwable) {
            return WorkflowMemo::MAX_MEMOS_PER_RUN;
        }

        return self::upsertRows(
            $decoded,
            $written,
            static fn (array $keys): int => WorkflowMemo::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('key', $keys)
                ->count(),
        );
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function searchAttributeRows(WorkflowRun $run, array $command, array &$written): int
    {
        if (! is_array($command['attributes'] ?? null)) {
            return WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN;
        }

        return self::upsertRows(
            $command['attributes'],
            $written,
            static fn (array $keys): int => WorkflowSearchAttribute::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('key', $keys)
                ->count(),
        );
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param array<string, true> $written keys written earlier in this completion
     * @param callable(list<string>): int $existing
     */
    private static function upsertRows(array $entries, array &$written, callable $existing): int
    {
        $deletes = [];
        $rows = 0;
        foreach ($entries as $key => $value) {
            $key = (string) $key;
            if ($value !== null) {
                $written[$key] = true;
                ++$rows;
            } elseif (isset($written[$key])) {
                // Deleting a key this completion wrote removes that row.
                unset($written[$key]);
                ++$rows;
            } else {
                $deletes[] = $key;
            }
        }

        return $rows + ($deletes === [] ? 0 : $existing(array_values(array_unique($deletes))));
    }

    /**
     * A child or continued run inherits the run's memos and search
     * attributes: one row each.
     */
    private static function inheritedMetadataRows(WorkflowRun $run): int
    {
        return WorkflowMemo::query()->where('workflow_run_id', $run->id)->count()
            + WorkflowSearchAttribute::query()->where('workflow_run_id', $run->id)->count();
    }
}
