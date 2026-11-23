<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\TaskWatchdog;

final class DefaultWorkflowTaskBridgeChildResumeTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    /**
     * @return iterable<string, array{string, ?string, positive-int, bool, int}>
     */
    public static function childStartTurnCases(): iterable
    {
        $fixture = file_get_contents(__DIR__ . '/../../Fixtures/V2/WorkerProtocol/child-start-turn.json');
        self::assertIsString($fixture);

        /** @var array{protocol_version: string, cases: array<string, array{await?: string, children: positive-int, close_parent: bool, parent_start_turns: int}>} $shared */
        $shared = json_decode($fixture, true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($shared['cases']);
        foreach ($shared['cases'] as $name => $case) {
            yield $name => [
                $shared['protocol_version'],
                $case['await'] ?? null,
                $case['children'],
                $case['close_parent'],
                $case['parent_start_turns'],
            ];
        }
    }

    #[DataProvider('childStartTurnCases')]
    public function testSharedChildStartTurnContract(
        string $protocolVersion,
        ?string $await,
        int $children,
        bool $closeParent,
        int $startTurns,
    ): void {
        $parent = $this->createWaitingRun();
        $original = $this->createLeasedTask($parent);
        $commands = [];
        for ($index = 1; $index <= $children; $index++) {
            $commands[] = $this->childStartCommand('child-' . $index, $await);
        }
        if ($closeParent) {
            $commands[] = [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('closed'),
            ];
        }
        $commands = WorkflowCommandNormalizer::normalize($commands, $protocolVersion);

        $receipt = $this->bridge->completeForProtocol($original->id, $commands, $protocolVersion);
        $this->assertTrue($receipt['completed']);
        $this->assertCount($children + $startTurns, $receipt['created_task_ids']);
        $this->assertSame($closeParent ? 'completed' : 'waiting', $receipt['run_status']);
        $redelivery = $this->bridge->completeForProtocol($original->id, $commands, $protocolVersion);
        $this->assertFalse($redelivery['completed']);
        $this->assertSame('task_not_leased', $redelivery['reason']);
        $this->assertSame([], $redelivery['created_task_ids']);
        $this->assertSame($startTurns, $this->openParentTaskCount($parent));
        $this->assertSame($children, WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->count());

        $starts = WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->id)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)->orderBy('sequence')->get();
        $this->assertCount($children, $starts);
        $first = $starts[0]->payload;
        if ($startTurns === 0) {
            if (! $closeParent) {
                $child = WorkflowTask::query()->where('workflow_run_id', $first['child_workflow_run_id'])->sole();
                $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
                $this->assertTrue($this->bridge->completeForProtocol($child->id, [[
                    'type' => 'complete_workflow',
                    'result' => Serializer::serialize('child-result'),
                ]], $protocolVersion)['completed']);

                $resumed = WorkflowTask::query()->where('workflow_run_id', $parent->id)
                    ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->sole();
                $this->assertSame('child', $resumed->payload['workflow_wait_kind']);
                $this->assertSame($first['child_call_id'], $resumed->payload['child_call_id']);
            }
            return;
        }

        $startTurn = WorkflowTask::query()->where('workflow_run_id', $parent->id)
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->assertContains($startTurn->id, $receipt['created_task_ids']);
        $expected = [
            'workflow_wait_kind' => 'child_started',
            'child_call_id' => $first['child_call_id'],
            'child_workflow_run_id' => $first['child_workflow_run_id'],
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $first['child_workflow_run_id'],
            'workflow_sequence' => $first['sequence'],
            'workflow_event_type' => 'ChildRunStarted',
        ];
        $payload = $startTurn->payload;
        ksort($expected);
        ksort($payload);
        $this->assertSame($expected, $payload);

        $this->assertTrue($this->bridge->claimStatus($startTurn->id, 'parent-first-worker')['claimed']);
        $history = $this->bridge->historyPayload($startTurn->id)['history_events'];
        $this->assertSame(
            array_merge(...array_fill(0, $children, ['ChildWorkflowScheduled', 'ChildRunStarted'])),
            array_column($history, 'event_type'),
        );

        $startTurn->refresh()
            ->forceFill([
                'lease_expires_at' => now()
                    ->subMinute(),
            ])->save();
        TaskWatchdog::runPass(respectThrottle: false, runIds: [$parent->id]);
        $this->assertTrue($this->bridge->claimStatus($startTurn->id, 'parent-replacement-worker')['claimed']);
        $this->assertSame($history, $this->bridge->historyPayload($startTurn->id)['history_events']);
        $this->assertSame($children, WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->count());

        // Continuing the child keeps the original identity in the start turn.
        $this->assertTrue($this->bridge->complete($startTurn->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]])['completed']);
        $child = WorkflowTask::query()->where('workflow_run_id', $first['child_workflow_run_id'])->sole();
        $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
        $continued = $this->bridge->complete($child->id, [[
            'type' => 'continue_as_new',
            'arguments' => Serializer::serialize(['continued-child']),
        ]]);
        $this->assertTrue($continued['completed']);
        $this->assertCount(1, $continued['created_task_ids']);
        $this->assertSame(0, $this->openParentTaskCount($parent));
        $this->assertSame($first['child_workflow_run_id'], $startTurn->fresh()->payload['child_workflow_run_id']);
        $continuedChild = $continued['created_task_ids'][0];
        $this->assertTrue($this->bridge->claimStatus($continuedChild, 'child-worker')['claimed']);
        $this->assertTrue($this->bridge->complete($continuedChild, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('first-result'),
        ]])['completed']);
        $resultTurn = WorkflowTask::query()->where('workflow_run_id', $parent->id)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->sole();
        $this->assertSame('child', $resultTurn->payload['workflow_wait_kind']);
        $this->assertSame($first['child_call_id'], $resultTurn->payload['child_call_id']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function childRunReplacements(): iterable
    {
        yield 'continue-as-new' => ['continue_as_new'];
        yield 'retry' => ['fail_workflow'];
    }

    #[DataProvider('childRunReplacements')]
    public function testExplicitChildIdSurvivesDuplicateCompletionAndRunReplacement(string $terminalCommand): void
    {
        $parent = $this->createWaitingRun('orders');
        $task = $this->createLeasedTask($parent);
        $instanceId = 'order:104-shipment_1';
        $command = [
            ...$this->childStartCommand('shipment'),
            'workflow_id' => $instanceId,
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ];

        $receipt = $this->bridge->complete($task->id, [$command]);
        $this->assertTrue($receipt['completed'], json_encode($receipt, JSON_THROW_ON_ERROR));
        $duplicate = $this->bridge->complete($task->id, [[
            ...$command,
            'workflow_id' => 'different-child',
        ]]);
        $this->assertSame('task_not_leased', $duplicate['reason']);
        $this->assertFalse(WorkflowInstance::query()->whereKey('different-child')->exists());

        $scheduled = $parent->historyEvents()
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled)->sole();
        $this->assertSame($instanceId, $scheduled->payload['requested_child_id']);
        $this->assertSame($instanceId, $scheduled->payload['child_workflow_instance_id']);
        $childCall = WorkflowChildCall::query()->where('parent_workflow_run_id', $parent->id)->sole();
        $this->assertSame($instanceId, $childCall->requested_child_id);
        $instance = WorkflowInstance::query()->findOrFail($instanceId);
        $this->assertSame($parent->namespace, $instance->namespace);
        $initial = $instance->runs()
            ->sole();
        $this->assertNotSame($instanceId, $initial->id);
        $this->assertNotSame($instanceId, $scheduled->payload['child_call_id']);
        $this->assertSame($initial->id, $scheduled->payload['child_workflow_run_id']);

        $parentTurn = WorkflowTask::query()->where('workflow_run_id', $parent->id)
            ->where('status', TaskStatus::Ready)->sole();
        $this->assertTrue($this->bridge->claimStatus($parentTurn->id, 'replacement-parent')['claimed']);
        $history = $this->bridge->historyPayload($parentTurn->id)['history_events'];
        $this->assertSame($instanceId, $history[0]['payload']['requested_child_id']);
        $this->assertSame($scheduled->payload['child_call_id'], $history[1]['payload']['child_call_id']);
        $this->assertTrue($this->bridge->complete($parentTurn->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]])['completed']);

        $childTask = WorkflowTask::query()->where('workflow_run_id', $initial->id)->sole();
        $this->assertTrue($this->bridge->claimStatus($childTask->id, 'child-worker')['claimed']);
        $outcome = $this->bridge->complete($childTask->id, [[
            'type' => $terminalCommand,
            ...match ($terminalCommand) {
                'continue_as_new' => [
                    'arguments' => Serializer::serialize(['next-shipment']),
                ],
                'fail_workflow' => [
                    'message' => 'retry shipment',
                    'failure_type' => 'ShipmentUnavailable',
                ],
            },
        ]]);
        $this->assertTrue($outcome['completed'], json_encode($outcome, JSON_THROW_ON_ERROR));
        $replacement = $instance->runs()
            ->where('run_number', 2)
            ->sole();
        $this->assertSame($instanceId, $replacement->workflow_instance_id);
        $this->assertNotSame($initial->id, $replacement->id);
        $this->assertSame($replacement->id, $instance->fresh()->current_run_id);
        $this->assertSame($initial->id, $scheduled->fresh()->payload['child_workflow_run_id']);
    }

    public function testExplicitChildIdCollisionRollsBackEarlierCommandsAndKeepsTheLease(): void
    {
        $winner = $this->createWaitingRun('unrelated-namespace');
        $winningTask = $this->createLeasedTask($winner);
        $id = 'reserved-shipment';
        $command = [
            ...$this->childStartCommand('shipment', 'result'),
            'workflow_id' => $id,
        ];
        $this->assertTrue($this->bridge->complete($winningTask->id, [$command])['completed']);
        $child = WorkflowInstance::query()->findOrFail($id);
        $childTask = WorkflowTask::query()->where('workflow_run_id', $child->current_run_id)->sole();
        $this->assertTrue($this->bridge->claimStatus($childTask->id, 'child-worker')['claimed']);
        $this->assertTrue($this->bridge->complete($childTask->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('fulfilled'),
        ]])['completed']);

        foreach (['unrelated-namespace', 'orders'] as $namespace) {
            $parent = $this->createWaitingRun($namespace);
            $losingTask = $this->createLeasedTask($parent);
            $commands = [[
                ...$command,
                'workflow_id' => 'rollback-me',
            ], $command];

            $receipt = $this->bridge->complete($losingTask->id, $commands);
            $this->assertSame('child_workflow_id_unavailable', $receipt['reason']);
            $this->assertSame([], $receipt['created_task_ids']);
            $this->assertSame(TaskStatus::Leased, $losingTask->fresh()->status);
            $this->assertFalse(WorkflowInstance::query()->whereKey('rollback-me')->exists());
            $this->assertSame(0, $parent->historyEvents()->count());
            $this->assertSame(0, WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->count());
            $this->assertSame(0, WorkflowChildCall::query()->where('parent_workflow_run_id', $parent->id)->count());
        }

        $this->assertSame($childTask->workflow_run_id, $child->fresh()->current_run_id);
        $this->assertSame(1, $child->runs()->count());
    }

    /**
     * @return iterable<string, array{TaskStatus, array<string, string>, bool}>
     */
    public static function existingParentTurns(): iterable
    {
        yield 'ready root replay carries the start' => [TaskStatus::Ready, [], false];
        yield 'leased root has its earlier history' => [TaskStatus::Leased, [], true];
        yield 'ready update handles the update' => [TaskStatus::Ready, [
            'workflow_update_id' => 'update-1',
        ], true];
    }

    #[DataProvider('existingParentTurns')]
    public function testOnlyAReadyRootReplayCanCarryAChildStart(
        TaskStatus $status,
        array $payload,
        bool $needsStartTask,
    ): void {
        $parent = $this->createWaitingRun();
        $original = $this->createLeasedTask($parent);
        $existing = $this->createLeasedTask($parent);
        $existing->forceFill([
            'status' => $status,
            'payload' => $payload,
        ])->save();

        $receipt = $this->bridge->complete($original->id, [$this->childStartCommand('child')]);
        $this->assertTrue($receipt['completed']);
        $this->assertCount($needsStartTask ? 2 : 1, $receipt['created_task_ids']);
        $this->assertSame($status, $existing->fresh()->status);
        $this->assertSame($payload, $existing->fresh()->payload);

        $turn = $existing;
        if ($needsStartTask) {
            $turn = WorkflowTask::query()->where('workflow_run_id', $parent->id)
                ->where('status', TaskStatus::Ready)->whereKeyNot($existing->id)->sole();
            $this->assertSame('child_started', $turn->payload['workflow_wait_kind']);
        }
        $this->assertTrue($this->bridge->claimStatus($turn->id, 'parent-next-worker')['claimed']);
        $this->assertSame([
            'ChildWorkflowScheduled', 'ChildRunStarted',
        ], array_column($this->bridge->historyPayload($turn->id)['history_events'], 'event_type'));
    }

    public function testOtherClosingParentTurnsDoNotCreateAStartTurn(): void
    {
        foreach (['fail_workflow', 'continue_as_new'] as $close) {
            $parent = $this->createWaitingRun();
            $task = $this->createLeasedTask($parent);
            $commands = [$this->childStartCommand('child'), [
                'type' => $close,
                ...match ($close) {
                    'fail_workflow' => [
                        'message' => 'closed',
                    ],
                    'continue_as_new' => [
                        'arguments' => Serializer::serialize(['continued']),
                    ],
                },
            ]];

            $completed = $this->bridge->complete($task->id, $commands);
            $this->assertTrue($completed['completed'], json_encode($completed, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $this->openParentTaskCount($parent));
            $this->assertSame(1, WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)
                ->where('link_type', 'child_workflow')
                ->count());
        }
    }

    public function testInvalidAwaitAndCheckpointRefuseBeforeStartingAnyChild(): void
    {
        $parent = $this->createWaitingRun();
        $task = $this->createLeasedTask($parent);
        $valid = $this->childStartCommand('valid');
        foreach ([
            [
                ...$valid,
                'await' => 'unknown',
            ],
            [
                ...$valid,
                'await' => null,
            ],
            [
                ...$valid,
                'await' => true,
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 10,
                'await' => 'started',
            ],
        ] as $invalid) {
            try {
                WorkflowCommandNormalizer::normalize([$valid, $invalid]);
                $this->fail('Expected await validation to refuse the completion.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('commands.1.await', $exception->errors());
            }
            $this->assertSame(
                'invalid_commands',
                $this->bridge->complete($task->id, [$valid, $invalid])['reason'],
            );
        }
        $checkpoint = $this->bridge->checkpointCancellationScopePrefix(
            $task->id,
            'external-worker-1',
            1,
            'child-start-with-retained-claim',
            1,
            [$valid],
        );
        $this->assertSame('child_start_turn_not_at_checkpoint', $checkpoint['reason']);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertSame(0, WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->id)->count());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function lateChildOutcomes(): iterable
    {
        foreach (['complete', 'fail', 'cancel', 'terminate'] as $outcome) {
            foreach (['timer', 'signal'] as $wait) {
                yield $outcome . '/' . $wait => [$outcome, $wait, false];
            }
        }
        yield 'legacy claim/timer' => ['complete', 'timer', true];
        yield 'terminal parent' => ['complete', 'terminal', false];
    }

    #[DataProvider('lateChildOutcomes')]
    public function testChildResolutionDuringParentLeaseCreatesAFollowUpAfterWaitingCompletion(
        string $outcome,
        string $wait,
        bool $legacyClaim,
    ): void {
        $parentRun = $this->createWaitingRun();
        $parentTask = $this->createLeasedTask($parentRun);
        $started = $this->bridge->complete($parentTask->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['first-child']),
            ],
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['second-child']),
            ],
        ]);
        $this->assertTrue($started['completed']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();
        $this->assertCount(2, $links);
        $childTasks = $links->map(static fn (WorkflowLink $link): WorkflowTask => WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->sole());

        $this->assertTrue($this->bridge->claimStatus($childTasks[0]->id, 'first-child-worker')['claimed']);
        $this->assertTrue($this->bridge->complete($childTasks[0]->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('first-result'),
        ]])['completed']);
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->assertTrue($this->bridge->claimStatus($resumeTask->id, 'parent-worker')['claimed']);
        if ($legacyClaim) {
            $payload = $resumeTask->fresh()
->payload;
            unset($payload['history_sequence_at_claim']);
            $resumeTask->forceFill([
                'payload' => $payload,
            ])->save();
        }
        $leasedHistory = $this->bridge->historyPayload($resumeTask->id);
        $this->assertCount(1, array_filter(
            $leasedHistory['history_events'],
            static fn (array $event): bool => $event['event_type'] === HistoryEventType::ChildRunCompleted->value
        ));

        if (in_array($outcome, ['cancel', 'terminate'], true)) {
            $closed = $this->app->make(WorkflowControlPlane::class)->{$outcome}($links[1]->child_workflow_instance_id);
            $this->assertTrue($closed['accepted'], json_encode($closed, JSON_THROW_ON_ERROR));
        } else {
            $this->assertTrue($this->bridge->claimStatus($childTasks[1]->id, 'second-child-worker')['claimed']);
            $closed = $this->bridge->complete($childTasks[1]->id, $outcome === 'complete' ? [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('second-result'),
            ]] : [[
                'type' => 'fail_workflow',
                'message' => 'second child failed',
            ]]);
            $this->assertTrue($closed['completed'], json_encode($closed, JSON_THROW_ON_ERROR));
        }
        $readyBeforeCompletion = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->count();
        $this->assertLessThanOrEqual(1, $readyBeforeCompletion);
        if (in_array($outcome, ['complete', 'fail'], true)) {
            $this->assertSame(0, $readyBeforeCompletion);
        }

        if ($wait === 'terminal') {
            $completed = $this->bridge->complete($resumeTask->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('parent-finished'),
            ]]);
            $this->assertTrue($completed['completed']);
            $this->assertSame(RunStatus::Completed->value, $completed['run_status']);
            $this->assertSame(0, $this->openParentTaskCount($parentRun));
            return;
        }

        $waiting = $this->bridge->complete($resumeTask->id, $wait === 'timer' ? [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]] : [[
            'type' => 'open_signal_wait',
            'signal_name' => 'unrelated-signal',
        ]]);
        $this->assertTrue($waiting['completed'], json_encode($waiting, JSON_THROW_ON_ERROR));
        $this->assertSame(RunStatus::Waiting->value, $waiting['run_status']);
        $readyTasks = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->get();
        $this->assertCount(1, $readyTasks, 'A child result outside the leased history needs a new delivery turn.');
        $followUp = $readyTasks->sole();
        $this->assertNotSame($resumeTask->id, $followUp->id);
        $this->assertTrue($followUp->available_at->lessThanOrEqualTo(now()));
        if ($readyBeforeCompletion === 0) {
            $this->assertContains($followUp->id, $waiting['created_task_ids']);
        }
        $this->assertTrue($this->bridge->claimStatus($followUp->id, 'next-parent-worker')['claimed']);
        $history = $this->bridge->historyPayload($followUp->id);
        $this->assertCount(2, array_filter(
            $history['history_events'],
            static fn (array $event): bool => in_array($event['event_type'], [
                HistoryEventType::ChildRunCompleted->value,
                HistoryEventType::ChildRunFailed->value,
                HistoryEventType::ChildRunCancelled->value,
                HistoryEventType::ChildRunTerminated->value,
            ], true)
        ));
        $completed = $this->bridge->complete($followUp->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['first-result', 'second-result']),
        ]]);
        $this->assertTrue($completed['completed']);
        $this->assertSame(RunStatus::Completed->value, $completed['run_status']);
        $this->assertSame('task_not_leased', $this->bridge->complete($followUp->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['first-result', 'second-result']),
        ]])['reason']);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
    }

    public function testResolutionsVisibleAtClaimStayCoalescedWithoutAnotherReplay(): void
    {
        $parent = $this->createWaitingRun();
        $started = $this->bridge->complete(
            $this->createLeasedTask($parent)
->id,
            array_map(static fn (string $name): array => [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize([$name]),
            ], ['first', 'second'])
        );
        $this->assertTrue($started['completed']);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->orderBy('sequence')->get();
        $this->assertCount(2, $links);
        foreach ($links as $link) {
            $child = WorkflowTask::query()->where('workflow_run_id', $link->child_workflow_run_id)->sole();
            $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
            $this->assertTrue($this->bridge->complete($child->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('child-result'),
            ]])['completed']);
        }
        $this->assertSame(1, $this->openParentTaskCount($parent));
        $resume = WorkflowTask::query()->where('workflow_run_id', $parent->id)
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->assertTrue($this->bridge->claimStatus($resume->id, 'parent-worker')['claimed']);
        $waiting = $this->bridge->complete($resume->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]]);
        $this->assertTrue($waiting['completed']);
        $this->assertSame(0, $this->openParentTaskCount($parent));
        $this->assertCount(1, $waiting['created_task_ids']);
    }

    public function testPartialParallelGroupDoesNotWakeParentBeforeItsOtherChild(): void
    {
        $parent = $this->createWaitingRun();
        $commands = [];
        foreach ([0, 1] as $index) {
            $entry = [
                'parallel_group_id' => 'parallel-children:1:2',
                'parallel_group_kind' => 'child',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => $index,
            ];
            $commands[] = [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize([$index]),
                ...$entry,
                'parallel_group_path' => [$entry],
            ];
        }
        $this->assertTrue($this->bridge->complete($this->createLeasedTask($parent)->id, $commands)['completed']);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->orderBy('sequence')->get();
        $this->assertCount(2, $links);
        // An unrelated delivery turn can be leased while the group is waiting.
        $resume = $this->createLeasedTask($parent);
        $resume->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        $this->assertTrue($this->bridge->claimStatus($resume->id, 'parent-worker')['claimed']);
        foreach ($links as $index => $link) {
            $child = WorkflowTask::query()->where('workflow_run_id', $link->child_workflow_run_id)->sole();
            $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
            $this->assertTrue($this->bridge->complete($child->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('child-result'),
            ]])['completed']);
            if ($index === 0) {
                $waiting = $this->bridge->complete($resume->id, [[
                    'type' => 'start_timer',
                    'delay_seconds' => 3600,
                ]]);
                $this->assertTrue($waiting['completed']);
                $this->assertSame(0, $this->openParentTaskCount($parent));
            }
        }
        $this->assertSame(1, $this->openParentTaskCount($parent));
    }

    /**
     * @return array{type: string, workflow_type: string, arguments: string, await?: string}
     */
    private function childStartCommand(string $name, ?string $await = 'started'): array
    {
        $command = [
            'type' => 'start_child_workflow',
            'workflow_type' => 'test-greeting-workflow',
            'arguments' => Serializer::serialize([$name]),
        ];
        if ($await !== null) {
            $command['await'] = $await;
        }

        return $command;
    }

    private function openParentTaskCount(WorkflowRun $run): int
    {
        return WorkflowTask::query()->where('workflow_run_id', $run->id)
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

    private function createWaitingRun(?string $namespace = null): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'namespace' => $namespace,
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
            'namespace' => $namespace,
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
