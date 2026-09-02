<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2ServiceModeParallelChildBarrierTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        self::stopWorkers();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        Queue::fake();

        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    public function testChildOnlyGroupWaitsForEveryChildInReverseCompletionOrder(): void
    {
        [$parentRun, $childTasks] = $this->stageChildGroup([
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
        ]);

        $this->completeChild($childTasks[1]);

        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

        /** @var WorkflowHistoryEvent $secondResolution */
        $secondResolution = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame(2, $secondResolution->payload['sequence'] ?? null);
        $this->assertSame('parallel-children:1:2', $secondResolution->payload['parallel_group_id'] ?? null);
        $this->assertSame(
            'parallel-children:1:2',
            $secondResolution->payload['parallel_group_path'][0]['parallel_group_id'] ?? null,
        );

        $this->completeChild($childTasks[0]);

        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();
        $resolvedWaits = WorkflowRunWait::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('kind', 'child')
            ->where('status', 'resolved')
            ->get();

        $this->assertCount(2, $resolvedWaits);
        $this->assertSame([$parentResumeTask->id], $resolvedWaits->pluck('task_id') ->unique() ->values() ->all());

        $duplicate = $this->bridge->complete($childTasks[1]->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize([
                'child' => 'duplicate',
            ]),
        ]]);

        $this->assertFalse($duplicate['completed']);
        $this->assertSame('task_not_leased', $duplicate['reason']);
        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
        $this->assertSame(2, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->count());
    }

    public function testMixedChildAndTimerGroupWakesOnceForEitherCompletionOrder(): void
    {
        foreach ([true, false] as $timerFirst) {
            $parentRun = $this->createWaitingRun();
            $parentTask = $this->createLeasedTask($parentRun);
            $childEntry = $this->parallelEntry('parallel-calls:1:2', 'mixed', 1, 2, 0);
            $timerEntry = $this->parallelEntry('parallel-calls:1:2', 'mixed', 1, 2, 1);

            $result = $this->bridge->complete($parentTask->id, [
                [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'test-service-mixed-child',
                    'arguments' => Serializer::serialize(['mixed-child']),
                    ...$childEntry,
                    'parallel_group_path' => [$childEntry],
                ],
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                    ...$timerEntry,
                    'parallel_group_path' => [$timerEntry],
                ],
            ]);

            $this->assertTrue($result['completed']);
            $this->assertCount(2, $result['created_task_ids']);

            /** @var WorkflowTask $childTask */
            $childTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][0]);
            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][1]);
            $completeChild = fn (): array => $this->completeChild($childTask);
            $fireTimer = fn () => $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

            $timerFirst ? $fireTimer() : $completeChild();

            $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

            $timerFirst ? $completeChild() : $fireTimer();

            $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
        }
    }

    public function testNestedChildGroupWaitsForInnerAndOuterSiblings(): void
    {
        $outer = fn (int $index): array => $this->parallelEntry('parallel-children:1:3', 'child', 1, 3, $index);
        $inner = fn (int $index): array => $this->parallelEntry('parallel-children:2:2', 'child', 2, 2, $index);

        [$parentRun, $childTasks] = $this->stageChildGroup([
            [$outer(0)],
            [$outer(1), $inner(0)],
            [$outer(2), $inner(1)],
        ]);

        $this->completeChild($childTasks[2]);
        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

        $this->completeChild($childTasks[1]);
        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

        $this->completeChild($childTasks[0]);
        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));

        $innerResolution = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->get()
            ->first(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 2);

        $this->assertInstanceOf(WorkflowHistoryEvent::class, $innerResolution);
        $this->assertSame(
            ['parallel-children:1:3', 'parallel-children:2:2'],
            collect($innerResolution->payload['parallel_group_path'] ?? [])
                ->pluck('parallel_group_id')
                ->all(),
        );
    }

    public function testRetryableChildFailureDoesNotWakeGroupBeforeRetryAndSiblingComplete(): void
    {
        $paths = [
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
        ];
        [$parentRun, $childTasks] = $this->stageChildGroup($paths, [[
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ]]);

        $this->failChild($childTasks[0], 'retryable child failure');

        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunFailed->value)
            ->count());

        /** @var WorkflowLink $retryLink */
        $retryLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->where('sequence', 1)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();
        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $retryLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->completeChild($retryTask);
        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

        $this->completeChild($childTasks[1]);
        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
    }

    public function testFinalChildFailureWakesGroupImmediatelyWhileSiblingIsOpen(): void
    {
        [$parentRun, $childTasks] = $this->stageChildGroup([
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
        ]);

        $this->failChild($childTasks[1], 'final child failure');

        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
        $this->assertSame(RunStatus::Pending, WorkflowRun::query()
            ->findOrFail($childTasks[0]->workflow_run_id)
            ->status);

        /** @var WorkflowHistoryEvent $failure */
        $failure = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunFailed->value)
            ->firstOrFail();

        $this->assertSame(2, $failure->payload['sequence'] ?? null);
        $this->assertSame('parallel-children:1:2', $failure->payload['parallel_group_id'] ?? null);
    }

    public function testCancelledAndTerminatedChildrenRetainFailFastSemantics(): void
    {
        foreach (['cancel', 'terminate'] as $operation) {
            [$parentRun, $childTasks] = $this->stageChildGroup([
                [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
                [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
            ]);

            $child = WorkflowStub::loadRun($childTasks[0]->workflow_run_id);
            $result = $operation === 'cancel'
                ? $child->cancel()
                : $child->terminate();

            $this->assertTrue($result->accepted());
            $this->assertSame($operation === 'cancel' ? 'cancelled' : 'terminated', $result->outcome());
            $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
            $this->assertSame(RunStatus::Pending, WorkflowRun::query()
                ->findOrFail($childTasks[1]->workflow_run_id)
                ->status);
        }
    }

    public function testConcurrentChildCompletionsSerializeOneBarrierWakeOnParentRun(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('A database with row-level locking is required.');
        }

        if (
            ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')
            || ! function_exists('stream_socket_pair')
        ) {
            $this->markTestSkipped('Process control and local sockets are required for concurrency coverage.');
        }

        self::stopWorkers();

        [$parentRun, $childTasks] = $this->stageChildGroup([
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
        ]);

        foreach ($childTasks as $index => $childTask) {
            $claim = $this->bridge->claimStatus($childTask->id, sprintf('concurrent-child-worker-%d', $index));
            $this->assertTrue($claim['claimed']);
        }

        $children = [];
        $results = [];

        DB::purge();

        try {
            foreach ($childTasks as $childTask) {
                $children[] = $this->forkDatabaseOperation(static function () use ($childTask): array {
                    /** @var WorkflowTaskBridge $bridge */
                    $bridge = app(WorkflowTaskBridge::class);

                    return $bridge->complete($childTask->id, [[
                        'type' => 'complete_workflow',
                        'result' => Serializer::serialize([
                            'child' => $childTask->workflow_run_id,
                        ]),
                    ]]);
                });
            }

            foreach ($children as $child) {
                $this->awaitDatabaseOperationReady($child);
            }

            foreach ($children as $child) {
                $this->releaseDatabaseOperation($child);
            }

            $results = array_map(fn (array $child): array => $this->awaitDatabaseOperation($child), $children);
            $children = [];
        } finally {
            foreach ($children as $child) {
                $this->terminateDatabaseOperation($child);
            }
        }

        DB::reconnect();

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $result['error'] ?? 'Concurrent child completion failed.');
            $this->assertTrue($result['result']['completed'] ?? false);
        }

        $this->assertSame(2, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->count());
        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
    }

    /**
     * @param list<list<array<string, mixed>>> $parallelPaths
     * @param list<array<string, mixed>> $commandOverrides
     * @return array{0: WorkflowRun, 1: list<WorkflowTask>}
     */
    private function stageChildGroup(array $parallelPaths, array $commandOverrides = []): array
    {
        $parentRun = $this->createWaitingRun();
        $parentTask = $this->createLeasedTask($parentRun);
        $commands = [];

        foreach ($parallelPaths as $index => $parallelPath) {
            $commands[] = [
                'type' => 'start_child_workflow',
                'workflow_type' => sprintf('test-service-child-%d', $index + 1),
                'arguments' => Serializer::serialize([sprintf('child-%d', $index + 1)]),
                ...($commandOverrides[$index] ?? []),
                ...$parallelPath[array_key_last($parallelPath)],
                'parallel_group_path' => $parallelPath,
            ];
        }

        $result = $this->bridge->complete($parentTask->id, $commands);

        $this->assertTrue($result['completed']);
        $this->assertSame(RunStatus::Waiting->value, $result['run_status']);

        $childTasks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowLink $link): WorkflowTask => WorkflowTask::query()
                ->where('workflow_run_id', $link->child_workflow_run_id)
                ->where('task_type', TaskType::Workflow->value)
                ->firstOrFail())
            ->values()
            ->all();

        $this->assertCount(count($parallelPaths), $childTasks);

        return [$parentRun, $childTasks];
    }

    private function completeChild(WorkflowTask $task): array
    {
        $claim = $this->bridge->claimStatus($task->id, sprintf('child-worker-%s', $task->id));

        $this->assertTrue($claim['claimed']);

        $result = $this->bridge->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize([
                'child' => $task->workflow_run_id,
            ]),
        ]]);

        $this->assertTrue($result['completed']);

        return $result;
    }

    private function failChild(WorkflowTask $task, string $message): array
    {
        $claim = $this->bridge->claimStatus($task->id, sprintf('child-worker-%s', $task->id));

        $this->assertTrue($claim['claimed']);

        $result = $this->bridge->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => $message,
            'exception_class' => RuntimeException::class,
        ]]);

        $this->assertTrue($result['completed']);

        return $result;
    }

    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }
     */
    private function parallelEntry(string $id, string $kind, int $base, int $size, int $index): array
    {
        return [
            'parallel_group_id' => $id,
            'parallel_group_kind' => $kind,
            'parallel_group_base_sequence' => $base,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    private function openParentWorkflowTaskCount(WorkflowRun $run): int
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
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
            'workflow_class' => 'Tests\\Fixtures\\V2\\TestServiceParentWorkflow',
            'workflow_type' => 'test-service-parent-workflow',
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
            'workflow_class' => 'Tests\\Fixtures\\V2\\TestServiceParentWorkflow',
            'workflow_type' => 'test-service-parent-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
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

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array{pid: int, socket: resource}
     */
    private function forkDatabaseOperation(callable $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Unable to fork a database operation.');

        if ($pid === 0) {
            fclose($sockets[0]);

            try {
                DB::reconnect();
                fwrite($sockets[1], json_encode([
                    'ready' => true,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);

                if (fgets($sockets[1]) !== 'go' . PHP_EOL) {
                    throw new RuntimeException('Concurrent child completion received an invalid release signal.');
                }

                $payload = [
                    'ok' => true,
                    'result' => $operation(),
                ];
            } catch (\Throwable $throwable) {
                $payload = [
                    'ok' => false,
                    'error' => $throwable::class . ': ' . $throwable->getMessage(),
                ];
            }

            fwrite($sockets[1], json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);
            fclose($sockets[1]);
            exit($payload['ok'] ? 0 : 1);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 5);

        return [
            'pid' => $pid,
            'socket' => $sockets[0],
        ];
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function awaitDatabaseOperationReady(array $child): void
    {
        $line = fgets($child['socket']);

        $this->assertIsString($line, 'A concurrent child completion did not become ready.');
        $this->assertSame([
            'ready' => true,
        ], json_decode($line, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function releaseDatabaseOperation(array $child): void
    {
        $this->assertSame(3, fwrite($child['socket'], 'go' . PHP_EOL));
    }

    /**
     * @param array{pid: int, socket: resource} $child
     * @return array{ok: bool, result?: array<string, mixed>, error?: string}
     */
    private function awaitDatabaseOperation(array $child): array
    {
        $deadline = microtime(true) + 10;
        $status = 0;

        do {
            $waitedPid = pcntl_waitpid($child['pid'], $status, WNOHANG);

            if ($waitedPid === $child['pid']) {
                break;
            }

            if ($waitedPid === -1) {
                $this->fail('Unable to wait for a concurrent child completion.');
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($waitedPid !== $child['pid']) {
            $this->terminateDatabaseOperation($child);
            $this->fail('A concurrent child completion did not finish within 10 seconds.');
        }

        $line = fgets($child['socket']);
        fclose($child['socket']);

        $this->assertIsString($line, 'A concurrent child completion returned no result.');
        $payload = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function terminateDatabaseOperation(array $child): void
    {
        @posix_kill($child['pid'], SIGKILL);
        @pcntl_waitpid($child['pid'], $status);

        if (is_resource($child['socket'])) {
            fclose($child['socket']);
        }
    }
}
