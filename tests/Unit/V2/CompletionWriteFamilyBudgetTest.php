<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CompletionRowEstimate;
use Workflow\V2\Support\HistoryTransactionMeter;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\TaskWatchdog;

/**
 * Workflow #1116: every write a workflow task completion makes is charged
 * before its statement runs, and an ordinary run's deadline close is bounded
 * and never left to an unclaimed task.
 */
final class CompletionWriteFamilyBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeFamilies(): iterable
    {
        foreach ([
            'timer',
            'activity',
            'child workflow',
            'memo and search attributes',
            'search attribute delete',
            'side effect and version marker',
            'local activity',
            'workflow completion',
            'workflow failure',
            'continue as new',
            'workflow failure with accepted updates',
        ] as $family) {
            yield $family => [$family];
        }
    }

    /**
     * Each family completes once to learn its exact row total. A limit equal
     * to it admits the completion; one lower refuses it before anything is
     * written, so every table is unchanged and the task keeps its lease.
     */
    #[DataProvider('writeFamilies')]
    public function testEachWriteFamilyIsAdmittedAtItsExactRowTotalAndRefusedOneBelow(string $family): void
    {
        // Tasks the completion creates run later, as their own completions.
        Queue::fake();
        $unmetered = [];
        $measured = $this->completeFamily($family, null, $unmetered);
        $this->assertTrue($measured['result']['completed'], json_encode($measured['result'], JSON_THROW_ON_ERROR));
        $rows = HistoryTransactionMeter::lastUsed()[HistoryTransactionMeter::ROWS];
        // No write statement of the completion ran outside the meter, except
        // the child-call projection, which runs after commit and was charged
        // its row in advance.
        $this->assertSame([], array_values(array_filter(
            $unmetered,
            static fn (string $sql): bool => preg_match(
                '/^\s*(insert into|update) .workflow_child_calls./i',
                $sql
            ) !== 1,
        )));
        $this->assertGreaterThan(0, $rows);
        $stateDriven = $family === 'workflow failure with accepted updates';
        if ($stateDriven) {
            // Rows that depend on stored state are not in the command estimate.
            $this->assertLessThan($rows, $measured['estimate']);
        } else {
            // The command estimate is exact for every command family.
            $this->assertSame($rows, $measured['estimate'], $family);
        }

        $admitted = $this->completeFamily($family, $rows);
        $this->assertTrue($admitted['result']['completed'], json_encode($admitted['result'], JSON_THROW_ON_ERROR));
        $this->assertSame($rows, HistoryTransactionMeter::lastUsed()[HistoryTransactionMeter::ROWS]);

        $refused = $this->completeFamily($family, $rows - 1);
        $this->assertFalse($refused['result']['completed']);
        if ($stateDriven) {
            // The meter is the exact backstop for state-driven rows.
            $this->assertSame('history_transaction_limit_exceeded', $refused['result']['reason']);
            $this->assertSame(HistoryTransactionMeter::ROWS, $refused['result']['limit'] ?? null);
        } else {
            // Refused from the command list before a single write statement.
            $this->assertSame('completion_budget_exceeded', $refused['result']['reason']);
            $this->assertSame([], $refused['writes']);
        }
        $this->assertSame($refused['before'], $this->databaseSnapshot(), 'A refused completion changed stored state.');
    }

    /**
     * An over-limit completion is refused before any statement writes: the
     * estimate is computed from the command list after the locks are taken.
     */
    public function testAnOverLimitCompletionIsRefusedBeforeAnyWriteStatement(): void
    {
        Queue::fake();
        $refused = $this->completeFamily('child workflow', 5);

        $this->assertSame('completion_budget_exceeded', $refused['result']['reason']);
        $this->assertSame([], $refused['writes']);
        $this->assertSame($refused['before'], $this->databaseSnapshot());
    }

    /**
     * A statement that may change several existing rows is counted before it
     * runs: deleting three search attributes charges three rows, not one.
     */
    public function testABulkWriteIsCountedBeforeItRuns(): void
    {
        $run = $this->createWaitingRun();
        $this->seedSearchAttributes($run, 3);
        $task = $this->createLeasedTask($run);
        $deletes = 0;
        DB::listen(static function ($query) use (&$deletes): void {
            if (preg_match('/^\s*delete\b.*search_attributes/i', $query->sql) === 1) {
                ++$deletes;
            }
        });

        $result = $this->bridge()
            ->complete($task->id, [$this->deleteSearchAttributes(3)]);

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $deletes);
        $this->assertSame(0, WorkflowSearchAttribute::query()->where('workflow_run_id', $run->id)->count());
        $withThree = HistoryTransactionMeter::lastUsed()[HistoryTransactionMeter::ROWS];

        $other = $this->createWaitingRun();
        $this->seedSearchAttributes($other, 3);
        WorkflowSearchAttribute::query()->where('workflow_run_id', $other->id)->where('key', '!=', 'attr_0')->delete();
        $otherTask = $this->createLeasedTask($other);
        $this->assertTrue($this->bridge()->complete($otherTask->id, [$this->deleteSearchAttributes(3)])['completed']);

        // Same statements, two fewer rows deleted.
        $this->assertSame($withThree - 2, HistoryTransactionMeter::lastUsed()[HistoryTransactionMeter::ROWS]);
    }

    public function testStatementRowsCountsTuplesOfAnInsertAndOneRowForOtherWrites(): void
    {
        $this->assertSame(0, HistoryTransactionMeter::statementRows('select * from "workflow_runs"'));
        $this->assertSame(1, HistoryTransactionMeter::statementRows('insert into "t" ("a", "b") values (?, ?)'));
        $this->assertSame(3, HistoryTransactionMeter::statementRows(
            'insert into `t` (`a`, `b`) values (?, ?), (?, ?), (?, ?) on duplicate key update `a` = values(`a`)'
        ));
        $this->assertSame(2, HistoryTransactionMeter::statementRows(
            'insert into "t" ("a") values (?), (?) on conflict ("a") do update set "a" = "excluded"."a"'
        ));
        $this->assertSame(1, HistoryTransactionMeter::statementRows('update "t" set "a" = ? where "id" = ?'));
        $this->assertSame(1, HistoryTransactionMeter::statementRows("\n  DELETE FROM t WHERE id IN (?, ?)"));
        $this->assertSame(0, HistoryTransactionMeter::statementRows('SAVEPOINT trans2'));
    }

    /**
     * The statement hook charges an insert by its tuples. An insert from a
     * select has none to count, so the engine must route one through
     * HistoryTransactionMeter::affecting() with its select; there is none.
     */
    public function testNoEngineWriteInsertsFromASelect(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match(
                    '/->(insertUsing|insertOrIgnoreUsing)\(/',
                    (string) file_get_contents($file->getPathname())
                ) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The confirmed #1116 defect: an ordinary run past its execution deadline
     * whose original workflow task is Ready and unclaimed used to stay
     * Pending. The watchdog now closes it under the run lock with that task.
     */
    public function testTheWatchdogClosesAnOrdinaryExpiredRunWhoseTaskIsUnclaimed(): void
    {
        $run = $this->createWaitingRun(RunStatus::Pending);
        $run->forceFill([
            'execution_deadline_at' => now()
                ->subMinute(),
        ])->save();
        $task = $this->createTask($run, TaskStatus::Ready);

        $report = TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame(1, $report['deadline_expired_candidates']);
        $this->assertSame(0, $report['deadline_expired_tasks_created']);
        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(['WorkflowTimedOut'], $this->eventTypes($run));
    }

    public function testTheWatchdogClosesAnOrdinaryExpiredRunWhoseLeaseExpired(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'run_deadline_at' => now()
                ->subMinute(),
        ])->save();
        $task = $this->createTask($run, TaskStatus::Leased, now()->subSecond());

        TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    /**
     * Expired runs whose close is not the watchdog's to take now (pending
     * cooperative cancellation with a Ready task) are not selected, and the
     * rest are taken oldest deadline first, so they cannot starve an
     * ordinary run behind a full scan.
     */
    public function testNoOpDeadlineCandidatesCannotStarveAnOrdinaryClose(): void
    {
        config([
            'workflows.v2.task_repair.scan_limit' => 3,
        ]);
        foreach (range(1, 5) as $index) {
            $waiting = $this->createWaitingRun();
            $waiting->forceFill([
                'execution_deadline_at' => now()
                    ->subHours(2),
                'cancellation_request_command_id' => 'cancel-' . $index,
                'cancellation_deadline_at' => now()
                    ->addHour(),
            ])->save();
            $this->createTask($waiting, TaskStatus::Ready);
        }
        $ordinary = $this->createWaitingRun();
        $ordinary->forceFill([
            'execution_deadline_at' => now()
                ->subMinute(),
        ])->save();
        $this->createTask($ordinary, TaskStatus::Ready);

        $report = TaskWatchdog::runPass();

        $this->assertSame(1, $report['deadline_expired_candidates']);
        $this->assertSame(RunStatus::Failed, $ordinary->fresh()->status);
    }

    /**
     * A live lease still defers the close to that worker's completion, which
     * closes the run before applying any command.
     */
    public function testALiveLeaseDefersTheCloseToTheWorkersCompletion(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'execution_deadline_at' => now()
                ->subMinute(),
        ])->save();
        $task = $this->createLeasedTask($run);

        $report = TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame(0, $report['deadline_expired_candidates']);
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);

        $result = $this->bridge()
            ->complete($task->id, [[
                'type' => 'start_timer',
                'delay_seconds' => 10,
            ]]);

        $this->assertSame('run_timed_out', $result['reason']);
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    /**
     * An oversized ordinary close is fenced and continued in bounded batches
     * by the watchdog, and writes exactly the history the single close
     * writes. A budget refusal never prevents it.
     */
    public function testAnOversizedDeadlineCloseFinishesInBoundedBatchesWithTheSingleCloseHistory(): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
            'workflows.v2.structural_limits.history_transaction_rows' => 0,
        ]);
        $single = $this->createExpiredRunWithOpenWork(activities: 3, timers: 4);
        $singleResult = $this->bridge()
            ->complete($single['task']->id, [$this->startTimer()]);
        $this->assertSame('run_timed_out', $singleResult['reason']);

        // A batch of two open items per close transaction.
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 1,
            'workflows.v2.structural_limits.history_transaction_rows' => 10,
        ]);
        $this->assertSame(1, WorkflowExecutor::deadlineCloseBatchSize());
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
        ]);
        $this->assertSame(2, WorkflowExecutor::deadlineCloseBatchSize());
        $batched = $this->createExpiredRunWithOpenWork(activities: 3, timers: 4);

        $first = $this->bridge()
            ->complete($batched['task']->id, [$this->startTimer()]);

        $this->assertFalse($first['completed']);
        $this->assertSame('run_deadline_closing', $first['reason']);
        $batched['run']->refresh();
        $this->assertSame(RunStatus::Waiting, $batched['run']->status);
        $this->assertSame(TaskStatus::Ready, $batched['task']->fresh()->status);
        $this->assertNull($batched['task']->fresh()->lease_owner);
        $this->assertSame(
            2,
            ActivityExecution::query()->where('workflow_run_id', $batched['run']->id)
                ->where('status', ActivityStatus::Cancelled->value)->count(),
        );
        // The worker's command was not applied.
        $this->assertSame(4, WorkflowTimer::query()->where('workflow_run_id', $batched['run']->id)->count());

        $passes = 0;
        while ($batched['run']->fresh()->status === RunStatus::Waiting && $passes < 10) {
            TaskWatchdog::runPass(runIds: [$batched['run']->id]);
            ++$passes;
        }

        // Seven open items, two per transaction: three more passes, the last
        // of which closes the run.
        $this->assertSame(3, $passes);
        $this->assertSame(RunStatus::Failed, $batched['run']->fresh()->status);
        $this->assertSame('timed_out', $batched['run']->fresh()->closed_reason);
        $this->assertSame(TaskStatus::Completed, $batched['task']->fresh()->status);
        $this->assertSame($this->closeHistory($single['run']), $this->closeHistory($batched['run']));
        $this->assertSame([
            'ActivityCancelled', 'ActivityCancelled', 'ActivityCancelled',
            'TimerCancelled', 'TimerCancelled', 'TimerCancelled', 'TimerCancelled',
            'WorkflowTimedOut',
        ], $this->eventTypes($batched['run']));
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $batched['run']->id)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->count());
    }

    /**
     * The terminal cascade of a bounded close is bounded too: accepted updates
     * and then children are settled at most one batch per transaction, and
     * the watchdog continues from the closing task's marker. The run's
     * history equals a single close's.
     *
     * 200 children and 300 updates over batches of 100 take five bounded
     * transactions. (A thousand children work the same way, but each child's
     * termination is a full command on that child, which makes a thousand of
     * them, twice, take ten minutes on SQLite.)
     */
    public function testABoundedCloseSettlesUpdatesAndChildrenAcrossPassesWithTheSingleCloseHistory(): void
    {
        Queue::fake();
        Carbon::setTestNow(now()->startOfSecond());
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
            'workflows.v2.structural_limits.history_transaction_rows' => 0,
        ]);
        $single = $this->createExpiredParent(children: 200, updates: 300);
        $this->assertSame(
            'run_timed_out',
            $this->bridge()
                ->complete($single['task']->id, [$this->startTimer()])['reason']
        );
        $this->assertSame(0, $this->openChildren($single['run']));
        $this->assertNull($single['task']->fresh()->payload['deadline_close_settlement'] ?? null);

        $batched = $this->createExpiredParent(children: 200, updates: 300);
        // 100 items per close transaction.
        config([
            'workflows.v2.structural_limits.history_transaction_rows' => 500,
        ]);
        $this->assertSame(100, WorkflowExecutor::deadlineCloseBatchSize());

        $closed = $this->bridge()
            ->complete($batched['task']->id, [$this->startTimer()]);

        $this->assertSame('run_timed_out', $closed['reason']);
        $this->assertSame(RunStatus::Failed, $batched['run']->fresh()->status);
        // The close took the first 100 updates and left the rest.
        $this->assertSame(200, $this->acceptedUpdates($batched['run']));
        $this->assertSame(200, $this->openChildren($batched['run']));
        $this->assertSame('pending', $batched['task']->fresh()->payload['deadline_close_settlement'] ?? null);

        $passes = 0;
        while (($batched['task']->fresh()->payload['deadline_close_settlement'] ?? null) === 'pending' && $passes < 20) {
            $updates = $this->acceptedUpdates($batched['run']);
            $children = $this->openChildren($batched['run']);
            TaskWatchdog::runPass(runIds: [$batched['run']->id]);
            ++$passes;
            // No pass settles more than one batch.
            $this->assertLessThanOrEqual(
                100,
                $updates - $this->acceptedUpdates($batched['run']) + $children - $this->openChildren($batched['run'])
            );
        }

        // 200 updates and 200 children left, 100 per pass.
        $this->assertSame(4, $passes);
        $this->assertSame('settled', $batched['task']->fresh()->payload['deadline_close_settlement']);
        $this->assertSame(0, $this->openChildren($batched['run']));
        $this->assertSame(0, $this->acceptedUpdates($batched['run']));
        $this->assertSame($this->closeHistory($single['run']), $this->closeHistory($batched['run']));
    }

    /**
     * @param list<string> $unmetered
     * @return array{result: array<string, mixed>, before: array<string, string>, estimate: int, writes: list<string>}
     */
    private function completeFamily(string $family, ?int $rowLimit, array &$unmetered = []): array
    {
        config([
            'workflows.v2.structural_limits.history_transaction_rows' => 0,
        ]);
        $run = $this->createWaitingRun();
        if ($family === 'search attribute delete') {
            $this->seedSearchAttributes($run, 3);
        }
        if ($family === 'workflow failure with accepted updates') {
            foreach (range(1, 3) as $index) {
                $this->acceptUpdate($run, $index);
            }
        }
        $task = $this->createLeasedTask($run);
        $commands = $this->familyCommands($family);
        $estimate = CompletionRowEstimate::rows($run, $commands);
        if ($rowLimit !== null) {
            config([
                'workflows.v2.structural_limits.history_transaction_rows' => $rowLimit,
            ]);
        }
        $before = $this->databaseSnapshot();
        $counting = true;
        $writes = [];
        DB::listen(static function ($query) use (&$unmetered, &$counting, &$writes): void {
            if (! $counting || HistoryTransactionMeter::statementRows($query->sql) === 0) {
                return;
            }
            $writes[] = $query->sql;
            if (HistoryTransactionMeter::current() === null) {
                $unmetered[] = $query->sql;
            }
        });

        $result = $this->bridge()
            ->complete($task->id, $commands);
        $counting = false;

        return [
            'result' => $result,
            'before' => $before,
            'estimate' => $estimate,
            'writes' => $writes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function familyCommands(string $family): array
    {
        return match ($family) {
            'timer' => [$this->startTimer()],
            'activity' => [[
                'type' => 'schedule_activity',
                'activity_type' => 'metered-activity',
                'arguments' => Serializer::serialize(['a']),
            ]],
            'child workflow' => [[
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child']),
            ]],
            'memo and search attributes' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::envelope([
                    'note' => 'metered',
                ]),
            ], [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'stage' => 'one',
                    'region' => 'two',
                ],
                'attribute_types' => [
                    'stage' => WorkflowSearchAttribute::TYPE_KEYWORD,
                    'region' => WorkflowSearchAttribute::TYPE_KEYWORD,
                ],
            ]],
            'search attribute delete' => [$this->deleteSearchAttributes(3)],
            'side effect and version marker' => [[
                'type' => 'record_side_effect',
                'result' => Serializer::serialize('side'),
            ], [
                'type' => 'record_version_marker',
                'change_id' => 'metered-step',
                'version' => 1,
                'min_supported' => 1,
                'max_supported' => 1,
            ]],
            'local activity' => [[
                'type' => 'record_local_activity',
                'activity_type' => 'metered-local-activity',
                'result' => Serializer::serialize('done'),
                'outcome' => 'completed',
                'attempts' => [[
                    'attempt_number' => 1,
                    'outcome' => 'completed',
                    'heartbeats' => [[
                        'elapsed_ms' => 1,
                    ]],
                ]],
                'execution_mode' => 'local',
            ]],
            'workflow completion' => [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('done'),
            ]],
            'workflow failure' => [[
                'type' => 'fail_workflow',
                'message' => 'metered failure',
                'exception_class' => \RuntimeException::class,
            ]],
            'workflow failure with accepted updates' => [[
                'type' => 'fail_workflow',
                'message' => 'metered failure',
                'exception_class' => \RuntimeException::class,
            ]],
            'continue as new' => [[
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['next']),
            ]],
        };
    }

    private function acceptUpdate(WorkflowRun $run, int $index): void
    {
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'instance',
            'payload_codec' => 'avro',
            'payload' => Serializer::serialize([
                'name' => 'approve-' . $index,
            ]),
            'source' => 'api',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $command->command_sequence,
            'update_name' => 'approve-' . $index,
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize([]),
            'accepted_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteSearchAttributes(int $count): array
    {
        $attributes = [];
        $types = [];
        for ($index = 0; $index < $count; ++$index) {
            $attributes['attr_' . $index] = null;
            $types['attr_' . $index] = WorkflowSearchAttribute::TYPE_KEYWORD;
        }

        return [
            'type' => 'upsert_search_attributes',
            'attributes' => $attributes,
            'attribute_types' => $types,
        ];
    }

    private function seedSearchAttributes(WorkflowRun $run, int $count): void
    {
        $task = $this->createLeasedTask($run);
        $attributes = [];
        $types = [];
        for ($index = 0; $index < $count; ++$index) {
            $attributes['attr_' . $index] = 'value-' . $index;
            $types['attr_' . $index] = WorkflowSearchAttribute::TYPE_KEYWORD;
        }
        $result = $this->bridge()
            ->complete($task->id, [[
                'type' => 'upsert_search_attributes',
                'attributes' => $attributes,
                'attribute_types' => $types,
            ]]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($count, WorkflowSearchAttribute::query()->where('workflow_run_id', $run->id)->count());
        WorkflowTask::query()->whereKeyNot($task->id)->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->delete();
        $run->forceFill([
            'status' => RunStatus::Waiting->value,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function startTimer(): array
    {
        return [
            'type' => 'start_timer',
            'delay_seconds' => 60,
        ];
    }

    /**
     * @return array{run: WorkflowRun, task: WorkflowTask}
     */
    private function createExpiredRunWithOpenWork(int $activities, int $timers): array
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'execution_deadline_at' => now()
                ->subMinute(),
        ])->save();
        for ($sequence = 1; $sequence <= $activities; ++$sequence) {
            $execution = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'activity_class' => 'metered-activity',
                'activity_type' => 'metered-activity',
                'status' => ActivityStatus::Pending->value,
                'arguments' => Serializer::serialize([]),
                'connection' => 'redis',
                'queue' => 'default',
            ]);
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => 'redis',
                'queue' => 'default',
            ]);
        }
        for ($index = 1; $index <= $timers; ++$index) {
            WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $activities + $index,
                'status' => TimerStatus::Pending->value,
                'delay_seconds' => 60,
                'fire_at' => now()
                    ->addMinute(),
            ]);
        }

        return [
            'run' => $run,
            'task' => $this->createLeasedTask($run),
        ];
    }

    /**
     * @return array{run: WorkflowRun, task: WorkflowTask}
     */
    private function createExpiredParent(int $children, int $updates): array
    {
        $run = $this->createWaitingRun();
        // Children are seeded as stored rows: starting a thousand children in
        // one completion is itself slow, and only their close is under test.
        foreach (range(1, $children) as $sequence) {
            $child = $this->createWaitingRun();
            WorkflowLink::query()->create([
                'link_type' => 'child_workflow',
                'sequence' => $sequence,
                'parent_workflow_instance_id' => $run->workflow_instance_id,
                'parent_workflow_run_id' => $run->id,
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'is_primary_parent' => true,
                'parent_close_policy' => 'terminate',
            ]);
        }
        foreach (range(1, $updates) as $index) {
            $this->acceptUpdate($run, $index);
        }
        $run->refresh()
            ->forceFill([
                'status' => RunStatus::Waiting->value,
                'execution_deadline_at' => now()
                    ->subMinute(),
            ])->save();

        return [
            'run' => $run,
            'task' => $this->createLeasedTask($run),
        ];
    }

    private function acceptedUpdates(WorkflowRun $run): int
    {
        return WorkflowUpdate::query()->where('workflow_run_id', $run->id)
            ->where('status', 'accepted')
            ->count();
    }

    private function openChildren(WorkflowRun $parent): int
    {
        return WorkflowRun::query()
            ->whereIn('id', WorkflowLink::query()
                ->where('parent_workflow_run_id', $parent->id)
                ->select('child_workflow_run_id'))
            ->whereNotIn(
                'status',
                [
                    RunStatus::Terminated->value,
                    RunStatus::Failed->value,
                    RunStatus::Completed->value,
                    RunStatus::Cancelled->value,
                ]
            )
            ->count();
    }

    /**
     * The close's history without per-run identities and clock values.
     *
     * @return list<array<string, mixed>>
     */
    private function closeHistory(WorkflowRun $run): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload_keys' => array_keys($event->payload),
                'payload' => self::withoutIdentities($event->payload),
            ])
            ->all();
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function withoutIdentities(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(^id$|_id$|_at$|^idempotency_key$|^task$|^stream_key$)/', $key) === 1) {
                unset($value[$key]);

                continue;
            }
            if (is_array($item)) {
                $value[$key] = self::withoutIdentities($item);
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function eventTypes(WorkflowRun $run): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($type): string => $type instanceof HistoryEventType ? $type->value : (string) $type)
            ->all();
    }

    /**
     * Every stored row of every table, so a refusal can be shown to change
     * nothing at all.
     *
     * @return array<string, string>
     */
    private function databaseSnapshot(): array
    {
        $connection = DB::connection();
        $snapshot = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, ['cache', 'cache_locks', 'migrations', 'sqlite_sequence'], true)) {
                continue;
            }
            $snapshot[$table] = hash('sha256', json_encode(
                $connection->table($table)
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))
                    ->values()
                    ->all(),
                JSON_THROW_ON_ERROR,
            ));
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function bridge(): WorkflowTaskBridge
    {
        return $this->app->make(WorkflowTaskBridge::class);
    }

    private function createWaitingRun(RunStatus $status = RunStatus::Waiting): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => $status->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        return $this->createTask($run, TaskStatus::Leased, now()->addMinutes(5));
    }

    private function createTask(WorkflowRun $run, TaskStatus $status, mixed $leaseExpiresAt = null): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => $status->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => $status === TaskStatus::Leased ? 'external-worker-1' : null,
            'lease_expires_at' => $leaseExpiresAt,
        ]);
    }
}
