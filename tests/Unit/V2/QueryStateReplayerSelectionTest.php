<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestQueryReplayGuardActivity;
use Tests\Fixtures\V2\TestQuerySelectionCancellationWorkflow;
use Tests\Fixtures\V2\TestQuerySelectionGroupWorkflow;
use Tests\Fixtures\V2\TestQuerySelectionWaitHandleWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
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
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancelDurableOperationCall;
use Workflow\V2\Support\DurableOperationHandle;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Workflow;

final class QueryStateReplayerSelectionTest extends TestCase
{
    #[DataProvider('projectedGroupOutcomes')]
    public function testGroupQueriesRestorePersistedActivityOutcomesWithoutTerminalEvents(
        string $firstStatus,
        string $secondStatus,
        bool $resolved,
        mixed $value,
        ?string $failure,
    ): void {
        $run = $this->createRun(TestQuerySelectionGroupWorkflow::class);
        foreach ([1, 2, 3] as $sequence) {
            $this->appendEvent($run, HistoryEventType::ActivityScheduled, $this->groupActivity($sequence));
        }
        $winner = $this->completeActivity($run, 3, 'fast-result', true);
        $this->appendSelection($run, $winner, true);
        foreach ([
            1 => $firstStatus,
            2 => $secondStatus,
        ] as $sequence => $status) {
            ActivityExecution::query()->create([
                'id' => 'activity-' . $sequence,
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'activity_type' => TestQueryReplayGuardActivity::class,
                'activity_class' => TestQueryReplayGuardActivity::class,
                'payload_codec' => $run->payload_codec,
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, []),
                'status' => $status,
                'result' => $status === ActivityStatus::Completed->value
                    ? Serializer::serializeWithCodec($run->payload_codec, 'row-result-' . $sequence) : null,
                'exception' => in_array(
                    $status,
                    [ActivityStatus::Failed->value, ActivityStatus::Cancelled->value],
                    true
                )
                    ? Serializer::serializeWithCodec($run->payload_codec, [
                        'class' => \RuntimeException::class,
                        'message' => 'recorded ' . $status . ' activity ' . $sequence,
                    ]) : null,
                'closed_at' => in_array($status, [ActivityStatus::Pending->value, ActivityStatus::Running->value], true)
                    ? null : now()
                        ->addSeconds($sequence),
            ]);
        }
        $expected = [
            'stage' => $resolved ? 'waiting-for-finish' : 'awaiting-group',
            'winner' => 'fast',
            'value' => $value,
            'failure' => $failure,
        ];
        $before = $this->snapshot($run);
        TestQueryReplayGuardActivity::$executions = 0;
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertInstanceOf($resolved ? SignalCall::class : DurableOperationHandle::class, $state->current);
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $this->snapshot($run));
        }
    }

    public static function projectedGroupOutcomes(): array
    {
        return [
            'pending first activity' => ['pending', 'completed', false, null, null],
            'running first activity' => ['running', 'completed', false, null, null],
            'pending second activity' => ['completed', 'pending', false, null, null],
            'both completed' => ['completed', 'completed', true, ['row-result-1', 'row-result-2'], null],
            'failed first activity' => ['failed', 'completed', true, null, 'recorded failed activity 1'],
            'cancelled first activity' => ['cancelled', 'completed', true, null, 'recorded cancelled activity 1'],
        ];
    }

    #[DataProvider('waitHandleOutcomes')]
    public function testQueriesRestoreWaitHandlesAfterAnotherSelectionMemberWins(
        string $kind,
        string $outcome,
        mixed $value,
        ?string $failure,
    ): void {
        $run = $this->createRun(TestQuerySelectionWaitHandleWorkflow::class, [$kind]);
        $wait = $this->selectionWait($kind, 1);
        $child = null;
        if ($kind === 'child') {
            $child = $this->createRun(TestTimerWorkflow::class, [60]);
            $child->forceFill([
                'status' => RunStatus::Completed->value,
                'closed_reason' => RunStatus::Completed->value,
                'closed_at' => now(),
                'output' => Serializer::serializeWithCodec($run->payload_codec, 'contradictory-child-projection'),
                'output_payload_codec' => $run->payload_codec,
            ])->save();
            $wait += [
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
            ];
            if ($failure === 'child-closed') {
                $failure = 'Child workflow ' . $child->id . ' closed as ' . $outcome . '.';
            }
        }
        $opening = match ($kind) {
            'timer' => HistoryEventType::TimerScheduled,
            'signal' => HistoryEventType::SignalWaitOpened,
            'child' => HistoryEventType::ChildWorkflowScheduled,
            default => HistoryEventType::ConditionWaitOpened,
        };
        $this->appendEvent($run, $opening, $wait);
        $fast = $this->selectionWait($kind, 2) + [
            'activity_type' => TestQueryReplayGuardActivity::class,
            'activity_execution_id' => 'activity-2',
        ];
        $this->appendEvent($run, HistoryEventType::ActivityScheduled, $fast);
        $winner = $this->appendEvent($run, HistoryEventType::ActivityCompleted, $fast + [
            'result' => Serializer::serializeWithCodec($run->payload_codec, 'fast-result'),
            'payload_codec' => $run->payload_codec,
        ]);
        $this->appendEvent($run, HistoryEventType::SelectionResolved, [
            'selection_group_id' => 'select-calls:1:2',
            'selection_group_base_sequence' => 1,
            'selection_group_size' => 2,
            'member_key' => 'fast',
            'member_index' => 1,
            'member_base_sequence' => 2,
            'member_size' => 1,
            'operation_kind' => 'activity',
            'operation_identity' => 'activity-2',
            'outcome' => 'completed',
            'resolution_event_id' => $winner->id,
            'resolution_event_type' => $winner->event_type->value,
        ]);
        if (in_array($outcome, ['fired-row', 'cancelled-row'], true)) {
            WorkflowTimer::query()->create([
                'id' => 'wait-timer',
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'status' => $outcome === 'fired-row' ? TimerStatus::Fired->value : TimerStatus::Cancelled->value,
                'delay_seconds' => 60,
                'fire_at' => now()
                    ->subSecond(),
                'fired_at' => $outcome === 'fired-row' ? now() : null,
            ]);
        } elseif ($outcome === 'fired') {
            $this->appendEvent($run, HistoryEventType::TimerFired, $wait);
        } elseif ($outcome === 'applied') {
            $this->appendEvent($run, HistoryEventType::SignalApplied, $wait + [
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, ['recorded-signal']),
                'payload_codec' => $run->payload_codec,
            ]);
        } elseif ($outcome === 'satisfied') {
            $this->appendEvent($run, HistoryEventType::ConditionWaitSatisfied, $wait);
        } elseif ($outcome === 'timed-out') {
            $this->appendEvent(
                $run,
                $kind === 'signal' ? HistoryEventType::TimerFired : HistoryEventType::ConditionWaitTimedOut,
                $kind === 'signal' ? $wait + [
                    'timer_kind' => 'signal_timeout',
                ] : $wait,
            );
        } elseif ($child !== null && $outcome !== 'pending') {
            $this->appendEvent($run, match ($outcome) {
                'completed' => HistoryEventType::ChildRunCompleted,
                'failed' => HistoryEventType::ChildRunFailed,
                'cancelled' => HistoryEventType::ChildRunCancelled,
                default => HistoryEventType::ChildRunTerminated,
            }, $wait + [
                'child_status' => $outcome,
                'output' => Serializer::serializeWithCodec($run->payload_codec, 'recorded-child'),
                'payload_codec' => $run->payload_codec,
                'exception_class' => \RuntimeException::class,
                'message' => 'recorded child failure',
            ]);
        }
        $resolved = $outcome !== 'pending';
        $expected = [
            'stage' => $resolved ? 'waiting-for-finish' : 'awaiting-group',
            'winner' => 'fast',
            'value' => $value,
            'failure' => $failure,
        ];
        $before = $this->snapshot($run);
        $childBefore = $child?->fresh()
            ->getAttributes();
        TestQueryReplayGuardActivity::$executions = 0;
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertInstanceOf($resolved ? SignalCall::class : DurableOperationHandle::class, $state->current);
            if (! $resolved) {
                $this->assertSame('group:1:1', $state->current->identity);
            }
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $this->snapshot($run));
            if ($child !== null) {
                $this->assertSame($childBefore, $child->fresh()->getAttributes());
            }
        }
    }

    public static function waitHandleOutcomes(): array
    {
        return [
            'pending timer' => ['timer', 'pending', null, null],
            'committed timer fire' => ['timer', 'fired', [true], null],
            'persisted timer fire without terminal event' => ['timer', 'fired-row', [true], null],
            'persisted timer cancellation without terminal event' => [
                'timer', 'cancelled-row', null, 'Durable timer operation [group:1:1] was cancelled.',
            ],
            'pending signal' => ['signal', 'pending', null, null],
            'committed signal' => ['signal', 'applied', ['recorded-signal'], null],
            'signal timeout' => ['signal', 'timed-out', [null], null],
            'pending condition' => ['condition', 'pending', null, null],
            'satisfied condition' => ['condition', 'satisfied', [true], null],
            'pending condition with timeout' => ['condition_timeout', 'pending', null, null],
            'satisfied condition with timeout' => ['condition_timeout', 'satisfied', [true], null],
            'condition timeout' => ['condition_timeout', 'timed-out', [false], null],
            'terminal child row without parent resolution stays pending' => ['child', 'pending', null, null],
            'committed child output overrides its projection' => ['child', 'completed', ['recorded-child'], null],
            'committed child failure overrides its completed projection' => [
                'child', 'failed', null, 'recorded child failure',
            ],
            'committed child cancellation overrides its completed projection' => [
                'child', 'cancelled', null, 'child-closed',
            ],
            'committed child termination overrides its completed projection' => [
                'child', 'terminated', null, 'child-closed',
            ],
        ];
    }

    #[DataProvider('groupOutcomes')]
    public function testQueriesRestoreTheLoserGroupFromCommittedOutcomes(
        string $outcome,
        mixed $value,
        ?string $failure
    ): void {
        $run = $this->createRun(TestQuerySelectionGroupWorkflow::class);
        foreach ([1, 2, 3] as $sequence) {
            $this->appendEvent($run, HistoryEventType::ActivityScheduled, $this->groupActivity($sequence));
        }
        $winner = $this->completeActivity($run, 3, 'fast-result', true);
        $this->appendSelection($run, $winner, true);
        if ($outcome === 'completed') {
            $this->completeActivity($run, 2, 'second-result', true);
            $this->completeActivity($run, 1, 'first-result', true);
        } elseif ($outcome === 'failed') {
            foreach ([[2, 'second failed first'], [1, 'first failed later']] as [$sequence, $message]) {
                $this->appendEvent($run, HistoryEventType::ActivityFailed, $this->groupActivity($sequence) + [
                    'exception_class' => \RuntimeException::class,
                    'message' => $message,
                ]);
            }
        } elseif ($outcome === 'cancelled') {
            foreach ([1, 2] as $sequence) {
                $this->appendEvent($run, HistoryEventType::ActivityCancelled, $this->groupActivity($sequence));
            }
            $this->appendEvent($run, HistoryEventType::SelectionOperationCancelled, [
                'selection_group_id' => 'select-calls:1:3',
                'member_key' => 'group',
                'member_index' => 0,
                'member_base_sequence' => 1,
                'member_size' => 2,
                'operation_kind' => 'group',
                'operation_identity' => 'group:1:2',
            ]);
        }
        $expected = [
            'stage' => $outcome === 'pending' ? 'awaiting-group' : 'waiting-for-finish',
            'winner' => 'fast',
            'value' => $value,
            'failure' => $failure,
        ];
        $before = $this->snapshot($run);
        TestQueryReplayGuardActivity::$executions = 0;
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertSame($expected, $state->workflow->currentState());
            if ($outcome === 'pending') {
                $this->assertInstanceOf(DurableOperationHandle::class, $state->current);
                $this->assertSame('group:1:2', $state->current->identity);
            } else {
                $this->assertInstanceOf(SignalCall::class, $state->current);
            }
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $this->snapshot($run));
        }
    }

    public static function groupOutcomes(): array
    {
        return [
            'pending loser' => ['pending', null, null],
            'reverse completion order preserves authored order' => [
                'completed',
                ['first-result', 'second-result'],
                null,
            ],
            'first committed failure wins over authored order' => ['failed', null, 'second failed first'],
            'group cancellation has authority over leaf failures' => [
                'cancelled',
                null,
                'Durable group operation [group:1:2] was cancelled.',
            ],
        ];
    }

    #[DataProvider('cancellationCommitEvidence')]
    public function testCompletionBeforeCancellationDoesNotInventAnUncommittedCancellation(
        ?string $taskType,
        ?string $status,
        bool $beforeSelection,
        bool $successor,
        bool $terminal,
        bool $committed,
        bool $memberCompleted = true,
    ): void {
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
        $run = $this->createRun(TestQuerySelectionCancellationWorkflow::class);
        foreach ([1, 2] as $sequence) {
            $this->appendEvent($run, HistoryEventType::ActivityScheduled, $this->singleActivity($sequence));
        }
        $winner = $this->completeActivity($run, 1, 'first-result', false);
        $this->appendSelection($run, $winner, false);
        if ($memberCompleted) {
            $this->completeActivity($run, 2, 'second-result', false);
        }
        if ($taskType !== null) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => $taskType,
                'status' => $status,
                'created_at' => $beforeSelection ? now()
                    ->subSecond() : now()
                    ->addSecond(),
            ]);
        }
        if ($successor) {
            $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
                'sequence' => 3,
                'signal_name' => 'finish',
            ]);
        }
        if ($terminal) {
            $run->forceFill([
                'status' => RunStatus::Terminated->value,
                'closed_reason' => RunStatus::Terminated->value,
                'closed_at' => now(),
            ])->save();
        }
        $before = $this->snapshot($run);
        $expected = [
            'stage' => $committed ? 'waiting-for-finish' : 'requesting-cancellation',
            'value' => $committed ? 'second-result' : null,
        ];
        TestQueryReplayGuardActivity::$executions = 0;
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertInstanceOf(
                $committed ? SignalCall::class : CancelDurableOperationCall::class,
                $state->current
            );
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $this->snapshot($run));
        }
    }

    public static function cancellationCommitEvidence(): array
    {
        return [
            'no workflow acknowledgement' => [null, null, false, false, false, false],
            'ready workflow task' => [TaskType::Workflow->value, TaskStatus::Ready->value, false, false, false, false],
            'leased workflow task' => [
                TaskType::Workflow->value,
                TaskStatus::Leased->value,
                false,
                false,
                false,
                false,
            ],
            'failed workflow task' => [
                TaskType::Workflow->value,
                TaskStatus::Failed->value,
                false,
                false,
                false,
                false,
            ],
            'older completed task cannot commit this boundary' => [
                TaskType::Workflow->value,
                TaskStatus::Completed->value,
                true,
                false,
                false,
                false,
            ],
            'activity acknowledgement cannot commit workflow cancellation' => [
                TaskType::Activity->value,
                TaskStatus::Completed->value,
                false,
                false,
                false,
                false,
            ],
            'completed workflow task' => [
                TaskType::Workflow->value,
                TaskStatus::Completed->value,
                false,
                false,
                false,
                true,
            ],
            'durable successor' => [null, null, false, true, false, true],
            'terminal run' => [null, null, false, false, true, true],
            'completed task does not replace a terminal member' => [
                TaskType::Workflow->value,
                TaskStatus::Completed->value,
                false,
                false,
                false,
                false,
                false,
            ],
        ];
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    private function createRun(string $workflow, array $arguments = []): WorkflowRun
    {
        $codec = CodecRegistry::defaultCodec();
        $instance = WorkflowInstance::query()->create([
            'id' => 'query-selection-' . strtolower((string) Str::ulid()),
            'workflow_class' => $workflow,
            'workflow_type' => $workflow,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflow,
            'workflow_type' => $workflow,
            'status' => RunStatus::Waiting->value,
            'payload_codec' => $codec,
            'arguments' => Serializer::serializeWithCodec($codec, $arguments),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function appendEvent(WorkflowRun $run, HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        $run->increment('last_history_sequence');

        return WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $run->last_history_sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }

    private function singleActivity(int $sequence): array
    {
        $entry = [
            'parallel_group_id' => 'select-calls:1:2',
            'parallel_group_kind' => 'activity',
            'parallel_group_mode' => 'select',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 2,
            'parallel_group_index' => $sequence - 1,
            'selection_member_key' => $sequence === 1 ? 'first' : 'second',
            'selection_member_index' => $sequence - 1,
            'selection_member_base_sequence' => $sequence,
            'selection_member_size' => 1,
            'selection_member_kind' => 'activity',
        ];

        return [
            'sequence' => $sequence,
            'activity_type' => TestQueryReplayGuardActivity::class,
            'activity_execution_id' => 'activity-' . $sequence,
        ] + $entry + [
            'parallel_group_path' => [$entry],
        ];
    }

    private function selectionWait(string $kind, int $sequence): array
    {
        $outer = [
            'parallel_group_id' => 'select-calls:1:2',
            'parallel_group_kind' => 'mixed',
            'parallel_group_mode' => 'select',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 2,
            'parallel_group_index' => $sequence - 1,
            'selection_member_key' => $sequence === 1 ? 'slow' : 'fast',
            'selection_member_index' => $sequence - 1,
            'selection_member_base_sequence' => $sequence,
            'selection_member_size' => 1,
            'selection_member_kind' => $sequence === 1 ? 'group' : 'activity',
        ];
        $path = [$outer];
        $payload = [
            'sequence' => $sequence,
        ];
        if ($sequence === 1) {
            $path[] = [
                'parallel_group_id' => ($kind === 'timer' ? 'parallel-timers' : 'parallel-children') . ':1:1',
                'parallel_group_kind' => str_starts_with($kind, 'condition') ? 'condition' : $kind,
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 1,
                'parallel_group_index' => 0,
            ];
            $payload += match ($kind) {
                'timer' => [
                    'timer_kind' => 'durable_timer',
                    'timer_id' => 'wait-timer',
                    'delay_seconds' => 60,
                ],
                'signal' => [
                    'signal_name' => 'slow',
                    'signal_wait_id' => 'wait-slow',
                    'timeout_seconds' => 60,
                ],
                'child' => [
                    'child_workflow_type' => TestTimerWorkflow::class,
                ],
                default => [
                    'condition_key' => 'slow.ready',
                    'condition_wait_id' => 'wait-condition',
                ],
            };
        }

        return $payload + $path[array_key_last($path)] + [
            'parallel_group_path' => $path,
        ];
    }

    private function groupActivity(int $sequence): array
    {
        $entry = [
            'parallel_group_id' => 'select-calls:1:3',
            'parallel_group_kind' => 'activity',
            'parallel_group_mode' => 'select',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 3,
            'parallel_group_index' => $sequence - 1,
            'selection_member_key' => $sequence === 3 ? 'fast' : 'group',
            'selection_member_index' => $sequence === 3 ? 1 : 0,
            'selection_member_base_sequence' => $sequence === 3 ? 3 : 1,
            'selection_member_size' => $sequence === 3 ? 1 : 2,
            'selection_member_kind' => $sequence === 3 ? 'activity' : 'group',
        ];
        $path = [$entry];
        if ($sequence !== 3) {
            $path[] = [
                'parallel_group_id' => 'parallel-activities:1:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => $sequence - 1,
            ];
        }

        return [
            'sequence' => $sequence,
            'activity_type' => TestQueryReplayGuardActivity::class,
            'activity_execution_id' => 'activity-' . $sequence,
        ] + $path[array_key_last($path)] + [
            'parallel_group_path' => $path,
        ];
    }

    private function completeActivity(WorkflowRun $run, int $sequence, string $value, bool $group): WorkflowHistoryEvent
    {
        return $this->appendEvent(
            $run,
            HistoryEventType::ActivityCompleted,
            ($group ? $this->groupActivity($sequence) : $this->singleActivity($sequence)) + [
                'result' => Serializer::serializeWithCodec($run->payload_codec, $value),
                'payload_codec' => $run->payload_codec,
            ]
        );
    }

    private function appendSelection(WorkflowRun $run, WorkflowHistoryEvent $winner, bool $group): void
    {
        $this->appendEvent($run, HistoryEventType::SelectionResolved, [
            'selection_group_id' => $group ? 'select-calls:1:3' : 'select-calls:1:2',
            'selection_group_base_sequence' => 1,
            'selection_group_size' => $group ? 3 : 2,
            'member_key' => $group ? 'fast' : 'first',
            'member_index' => $group ? 1 : 0,
            'member_base_sequence' => $group ? 3 : 1,
            'member_size' => 1,
            'operation_kind' => 'activity',
            'operation_identity' => 'activity-' . ($group ? 3 : 1),
            'outcome' => 'completed',
            'resolution_event_id' => $winner->id,
            'resolution_event_type' => $winner->event_type->value,
        ]);
    }

    private function snapshot(WorkflowRun $run): array
    {
        return [
            'run' => $run->fresh()
                ->getAttributes(),
            'history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy(
                'sequence'
            )->get()
                ->toArray(),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $run->id)->get()->toArray(),
            'tasks' => WorkflowTask::query()->where('workflow_run_id', $run->id)->get()->toArray(),
            'activities' => ActivityExecution::query()->where('workflow_run_id', $run->id)->get()->toArray(),
            'timers' => WorkflowTimer::query()->where('workflow_run_id', $run->id)->get()->toArray(),
            'signals' => WorkflowSignal::query()->where('workflow_run_id', $run->id)->get()->toArray(),
        ];
    }
}
