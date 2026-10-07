<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestQueryReplayGuardActivity;
use Tests\Fixtures\V2\TestQuerySelectionCancellationWorkflow;
use Tests\Fixtures\V2\TestQuerySelectionGroupWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
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
        foreach ([1 => $firstStatus, 2 => $secondStatus] as $sequence => $status) {
            ActivityExecution::query()->create([
                'id' => 'activity-' . $sequence,
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'activity_type' => TestQueryReplayGuardActivity::class,
                'payload_codec' => $run->payload_codec,
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, []),
                'status' => $status,
                'result' => $status === ActivityStatus::Completed->value
                    ? Serializer::serializeWithCodec($run->payload_codec, 'row-result-' . $sequence) : null,
                'exception' => in_array($status, [ActivityStatus::Failed->value, ActivityStatus::Cancelled->value], true)
                    ? Serializer::serializeWithCodec($run->payload_codec, [
                        'class' => \RuntimeException::class,
                        'message' => 'recorded ' . $status . ' activity ' . $sequence,
                    ]) : null,
                'closed_at' => in_array($status, [ActivityStatus::Pending->value, ActivityStatus::Running->value], true)
                    ? null : now()->addSeconds($sequence),
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
    private function createRun(string $workflow): WorkflowRun
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
            'arguments' => Serializer::serializeWithCodec($codec, []),
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
