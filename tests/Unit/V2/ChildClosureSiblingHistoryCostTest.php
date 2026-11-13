<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestParallelGreetingChildrenWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * Closing one child must cost the parent the closing child's link and the
 * parent's own state, not every sibling's history.
 */
final class ChildClosureSiblingHistoryCostTest extends TestCase
{
    /**
     * @var array<string, int>
     */
    private array $historyRowsByRun = [];

    private int $historyQueries = 0;

    private int $queries = 0;

    private bool $measuring = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Event::listen(
            'eloquent.retrieved: ' . WorkflowHistoryEvent::class,
            function (WorkflowHistoryEvent $event): void {
                if ($this->measuring) {
                    $runId = (string) $event->workflow_run_id;
                    $this->historyRowsByRun[$runId] = ($this->historyRowsByRun[$runId] ?? 0) + 1;
                }
            },
        );
        DB::listen(function (QueryExecuted $query): void {
            if ($this->measuring && str_contains($query->sql, 'workflow_history_events')) {
                $this->historyQueries++;
            }
            if ($this->measuring) {
                $this->queries++;
            }
        });
    }

    public function testEmbeddedChildClosureDoesNotReadSiblingHistories(): void
    {
        $small = $this->embeddedClosureCost(2);
        $large = $this->embeddedClosureCost(6);

        $this->assertSame(0, $small['sibling_rows'], json_encode($small, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $large['sibling_rows'], json_encode($large, JSON_THROW_ON_ERROR));
        $this->assertSame(
            $small['history_queries_first_last_close'],
            $large['history_queries_first_last_close'],
            json_encode([$small, $large], JSON_THROW_ON_ERROR),
        );
    }

    public function testBridgeChildClosureDoesNotReadSiblingHistories(): void
    {
        foreach ([false, true] as $parallel) {
            $small = $this->bridgeClosureCost(2, $parallel);
            $large = $this->bridgeClosureCost(6, $parallel);

            $this->assertSame(0, $small['sibling_rows'], json_encode($small, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $large['sibling_rows'], json_encode($large, JSON_THROW_ON_ERROR));
            $this->assertSame(
                $small['history_queries_first_last_close'],
                $large['history_queries_first_last_close'],
                json_encode([$small, $large], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testChildClosureQueryCountDoesNotGrowWithFanOut(): void
    {
        // The parent re-projection reuses the child runs it batch-loads with
        // its child links, so a close costs the same number of queries however
        // many siblings the parent has (#892). Before, each sibling was fetched
        // again for every wait, lineage and summary lookup.
        $small = $this->embeddedClosureCost(2)['queries_per_close'];
        $large = $this->embeddedClosureCost(6)['queries_per_close'];
        $evidence = json_encode([$small, $large], JSON_THROW_ON_ERROR);

        $this->assertLessThanOrEqual(max(array_slice($small, 0, -1)), max(array_slice($large, 0, -1)), $evidence);
        $this->assertSame($small[count($small) - 1], $large[count($large) - 1], $evidence);

        foreach ([false, true] as $parallel) {
            $small = $this->bridgeClosureCost(2, $parallel)['queries_per_close'];
            $large = $this->bridgeClosureCost(6, $parallel)['queries_per_close'];
            $evidence = json_encode([$parallel, $small, $large], JSON_THROW_ON_ERROR);

            // The close that completes a parallel group records every member's
            // resolution once, so only the closes before it are compared.
            $this->assertLessThanOrEqual(
                max(array_slice($small, 0, -1)),
                max(array_slice($large, 0, -1)),
                $evidence,
            );
        }
    }

    /**
     * @return array{sibling_rows: int, history_queries_first_last_close: list<int>, sibling_rows_per_close: list<int>, queries_per_close: list<int>}
     */
    private function embeddedClosureCost(int $children): array
    {
        $workflow = WorkflowStub::make(TestParallelGreetingChildrenWorkflow::class, 'sibling-cost-' . $children);
        $workflow->start($children);
        $parentId = $workflow->runId();
        $this->runReadyTask($parentId, TaskType::Workflow);
        $childIds = $this->childRunIds($parentId);
        $this->assertCount($children, $childIds);

        // Give every sibling a full history before any of them closes.
        foreach ($childIds as $childId) {
            $this->runReadyTask($childId, TaskType::Workflow);
            $this->runReadyTask($childId, TaskType::Activity);
        }

        $cost = $this->measureClosures(
            $childIds,
            fn (string $childId) => $this->runReadyTask($childId, TaskType::Workflow),
        );

        $this->assertSame(1, $this->openWorkflowTaskCount($parentId));
        $this->assertSame($children, WorkflowHistoryEvent::query()->where('workflow_run_id', $parentId)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)->count());
        $this->runReadyTask($parentId, TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(
            array_map(static fn (int $index): string => sprintf('Hello, %d!', $index), range(0, $children - 1)),
            array_column($workflow->output(), 'greeting'),
        );

        return $cost;
    }

    /**
     * @return array{sibling_rows: int, history_queries_first_last_close: list<int>, sibling_rows_per_close: list<int>, queries_per_close: list<int>}
     */
    private function bridgeClosureCost(int $children, bool $parallel): array
    {
        /** @var WorkflowTaskBridge $bridge */
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $parent = $this->createWaitingRun();
        $commands = [];

        for ($index = 0; $index < $children; $index++) {
            $command = [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-' . $index]),
            ];

            if ($parallel) {
                $entry = [
                    'parallel_group_id' => sprintf('parallel-children:1:%d', $children),
                    'parallel_group_kind' => 'child',
                    'parallel_group_base_sequence' => 1,
                    'parallel_group_size' => $children,
                    'parallel_group_index' => $index,
                ];
                $command = [
                    ...$command,
                    ...$entry,
                    'parallel_group_path' => [$entry],
                ];
            }

            $commands[] = $command;
        }

        $this->assertTrue($bridge->complete($this->createLeasedTask($parent)->id, $commands)['completed']);
        $childIds = $this->childRunIds($parent->id);
        $this->assertCount($children, $childIds);

        $cost = $this->measureClosures($childIds, function (string $childId) use ($bridge): void {
            $task = WorkflowTask::query()->where('workflow_run_id', $childId)->sole();
            $this->assertTrue($bridge->claimStatus($task->id, 'child-worker')['claimed']);
            $this->assertTrue($bridge->complete($task->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('child-result'),
            ]])['completed']);
        });

        $this->assertSame(1, $this->openWorkflowTaskCount($parent->id));
        $this->assertSame($children, WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)->count());

        return $cost;
    }

    /**
     * @param list<string> $childIds
     * @param callable(string): void $close
     * @return array{sibling_rows: int, history_queries_first_last_close: list<int>, sibling_rows_per_close: list<int>, queries_per_close: list<int>}
     */
    private function measureClosures(array $childIds, callable $close): array
    {
        $siblingRowsPerClose = [];
        $historyQueriesPerClose = [];
        $queriesPerClose = [];

        foreach ($childIds as $childId) {
            $this->historyRowsByRun = [];
            $this->historyQueries = 0;
            $this->queries = 0;
            $this->measuring = true;

            try {
                $close($childId);
            } finally {
                $this->measuring = false;
            }

            $siblingRows = 0;
            foreach ($childIds as $siblingId) {
                if ($siblingId !== $childId) {
                    $siblingRows += $this->historyRowsByRun[$siblingId] ?? 0;
                }
            }

            $siblingRowsPerClose[] = $siblingRows;
            $historyQueriesPerClose[] = $this->historyQueries;
            $queriesPerClose[] = $this->queries;
        }

        return [
            'sibling_rows' => array_sum($siblingRowsPerClose),
            'sibling_rows_per_close' => $siblingRowsPerClose,
            'history_queries_first_last_close' => [
                $historyQueriesPerClose[0],
                $historyQueriesPerClose[count($historyQueriesPerClose) - 1],
            ],
            'queries_per_close' => $queriesPerClose,
        ];
    }

    /**
     * @return list<string>
     */
    private function childRunIds(string $parentId): array
    {
        /** @var list<string> $ids */
        $ids = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->pluck('child_workflow_run_id')
            ->all();

        return $ids;
    }

    private function runReadyTask(string $runId, TaskType $taskType): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $job = $taskType === TaskType::Activity
            ? new RunActivityTask($task->id)
            : new RunWorkflowTask($task->id);

        $this->app->call([$job, 'handle']);
    }

    private function openWorkflowTaskCount(string $runId): int
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->count();
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return $task;
    }

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
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
}
