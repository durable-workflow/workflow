<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelActivityBatchWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\WorkflowStub;

final class V2EmbeddedParallelActivityConcurrencyTest extends TestCase
{
    public static function completionSnapshots(): iterable
    {
        foreach ([false, true] as $named) {
            foreach ([2, 6, 25, 'nested'] as $shape) {
                yield ($named ? 'named' : 'default') . '/' . $shape => [$shape, $named];
            }
        }
    }

    #[DataProvider('completionSnapshots')]
    public function testOlderOutcomeSnapshotObservesTheCommittedSibling(int|string $shape, bool $named): void
    {
        self::stopWorkers();
        Queue::fake();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL repeatable-read snapshots are required.');
        }

        $originalStorage = config('workflows.storage.connection');
        $database = DB::connection()->getConfig();
        unset($database['name']);
        $database['url'] = null;
        config([
            'database.connections.parallel_join_storage' => $database,
            'database.connections.parallel_join_sibling' => $database,
            'workflows.storage.connection' => $named ? 'parallel_join_storage' : null,
            'workflows.storage.transaction_attempts' => 1,
        ]);
        $storage = config('workflows.storage.connection');
        $primary = DB::connection($storage);
        $sibling = DB::connection('parallel_join_sibling');
        $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $sibling->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame($primary->getPdo(), $sibling->getPdo());

        $nested = $shape === 'nested';
        if (! $nested) {
            $this->assertIsInt($shape);
        }
        $count = $nested ? 3 : $shape;
        $workflow = WorkflowStub::make($nested
            ? TestNestedParallelActivityWorkflow::class : TestParallelActivityBatchWorkflow::class);
        if ($nested) {
            $workflow->start('0', '1', '2');
        } else {
            $workflow->start($count);
        }
        $runId = $workflow->runId();
        $this->runWorkflowTask($runId);
        $tasks = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)->orderBy('id')->get();
        $this->assertCount($count, $tasks);
        foreach ($tasks->take($count - 2) as $task) {
            app()->call([new RunActivityTask($task->id), 'handle']);
        }
        $first = $tasks[$count - 2];
        $last = $tasks[$count - 1];
        $armed = true;
        $observed = false;
        DB::listen(function (QueryExecuted $event) use (
            &$armed,
            &$observed,
            $primary,
            $storage,
            $first,
            $last,
            $runId,
        ): void {
            if (! $armed || $event->connection !== $primary
                || ! str_starts_with($event->sql, 'select exists(')
                || ! str_contains($event->sql, 'activity_attempts')
                || ! in_array($last->id, $event->bindings, true)) {
                return;
            }
            $recording = array_filter(
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
                static fn (array $frame): bool => ($frame['class'] ?? null) === ActivityOutcomeRecorder::class
                    && ($frame['function'] ?? null) === 'record'
            );
            if ($recording === []) {
                return;
            }
            $armed = false;
            $observed = true;
            $this->assertGreaterThan(0, $primary->transactionLevel());

            // The last outcome now has its real pre-run-lock read view. Commit
            // its sibling on an independent PDO before it can take the run lock.
            config([
                'workflows.storage.connection' => 'parallel_join_sibling',
            ]);
            try {
                app()->call([new RunActivityTask($first->id), 'handle']);
                $this->assertSame(0, DB::connection('parallel_join_sibling')->transactionLevel());
                $this->assertSame(0, $this->openWorkflowTasks($runId)->count());
            } finally {
                config([
                    'workflows.storage.connection' => $storage,
                ]);
            }
        });

        try {
            app()->call([new RunActivityTask($last->id), 'handle']);
            $this->assertTrue($observed, 'The production outcome snapshot boundary was not exercised.');
            $this->assertSame(0, $primary->transactionLevel());
            $this->assertSame($count, ActivityExecution::query()->where('workflow_run_id', $runId)
                ->where('status', 'completed')
                ->where('attempt_count', 1)
                ->count());
            $this->assertSame($count, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityCompleted->value)->count());
            $this->assertSame(1, $this->openWorkflowTasks($runId)->count());

            // Duplicate delivery must neither repeat an outcome nor add a wake-up.
            app()
                ->call([new RunActivityTask($last->id), 'handle']);
            $this->assertSame(1, $this->openWorkflowTasks($runId)->count());
            $this->assertSame($count, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityCompleted->value)->count());
            $this->runWorkflowTask($runId);
            $this->assertTrue($workflow->refresh()->completed());
            $expected = array_map(static fn (int $index): string => "Hello, {$index}!", range(0, $count - 1));
            $this->assertSame(
                $nested ? [$expected[0], [$expected[1], $expected[2]]] : $expected,
                $nested ? $workflow->output()['results'] : $workflow->output()
            );
            $this->assertSame(0, $this->openWorkflowTasks($runId)->count());
        } finally {
            $armed = false;
            config([
                'workflows.storage.connection' => $originalStorage,
            ]);
            DB::purge('parallel_join_storage');
            DB::purge('parallel_join_sibling');
        }
    }

    public static function batchSizes(): iterable
    {
        yield 'six' => [6];
        yield 'twenty-five' => [25];
    }

    #[DataProvider('batchSizes')]
    public function testParallelActivitiesCompleteWithQueueWorkers(int $count): void
    {
        $workflow = WorkflowStub::make(TestParallelActivityBatchWorkflow::class);
        $workflow->start($count);
        $this->waitForWorkflow($workflow, static fn (WorkflowStub $run): bool => ! $run->refresh()->running());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(
            array_map(static fn (int $index): string => "Hello, {$index}!", range(0, $count - 1)),
            $workflow->output()
        );
        $this->assertSame($count, ActivityExecution::query()->where('workflow_run_id', $workflow->runId())
            ->where('status', 'completed')
            ->where('attempt_count', 1)
            ->count());
        $this->assertSame(2, WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)->count());
        $this->assertSame(0, $this->openWorkflowTasks($workflow->runId())->count());
    }

    private function openWorkflowTasks(string $runId): Builder
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value]);
    }

    private function runWorkflowTask(string $runId): void
    {
        $task = $this->openWorkflowTasks($runId)
            ->sole();
        app()
            ->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
