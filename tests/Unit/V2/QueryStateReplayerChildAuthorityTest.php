<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestQueryChildMixedWaitWorkflow;
use Tests\Fixtures\V2\TestQueryParallelChildAuthorityWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\SignalCall;

final class QueryStateReplayerChildAuthorityTest extends TestCase
{
    #[DataProvider('terminalChildren')]
    public function testParallelChildrenWaitForTheParentResolutionCommit(string $status, bool $started): void
    {
        $parent = $this->createRun(TestQueryParallelChildAuthorityWorkflow::class, RunStatus::Waiting, []);
        $payloads = [];
        foreach ([1, 2] as $sequence) {
            $child = $this->createRun(TestTimerWorkflow::class, RunStatus::from($status), []);
            $child->forceFill([
                'closed_at' => now(),
                'closed_reason' => $status,
                'output' => Serializer::serialize('uncommitted-child-output'),
                'output_payload_codec' => $child->payload_codec,
            ])->save();
            $link = WorkflowLink::query()->create([
                'link_type' => 'child_workflow',
                'sequence' => $sequence,
                'parent_workflow_instance_id' => $parent->workflow_instance_id,
                'parent_workflow_run_id' => $parent->id,
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'is_primary_parent' => true,
            ]);
            $payload = [
                'workflow_link_id' => $link->id,
                'child_call_id' => $link->id,
                'sequence' => $sequence,
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'child_workflow_type' => TestTimerWorkflow::class,
                'child_workflow_class' => TestTimerWorkflow::class,
                'child_run_number' => 1,
                'parallel_group_id' => 'parallel-children:1:2',
                'parallel_group_kind' => 'child',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => $sequence - 1,
            ];
            $this->appendEvent($parent, HistoryEventType::ChildWorkflowScheduled, $payload);
            $payloads[$sequence] = $payload;
            if ($started) {
                $this->appendEvent($parent, HistoryEventType::ChildRunStarted, $payload);
            }
        }
        $before = $this->snapshot();
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($parent->fresh());
            $this->assertInstanceOf(AllCall::class, $state->current);
            $this->assertSame([
                'stage' => 'waiting-for-children',
                'value' => null,
                'failure' => null,
            ], $state->workflow->currentState());
            $this->assertSame(
                $state->workflow->currentState(),
                (new QueryStateReplayer())->query($parent->fresh(), 'currentState')
            );
            $this->assertSame($before, $this->snapshot());
        }
        $event = match (RunStatus::from($status)) {
            RunStatus::Completed => HistoryEventType::ChildRunCompleted,
            RunStatus::Failed => HistoryEventType::ChildRunFailed,
            RunStatus::Cancelled => HistoryEventType::ChildRunCancelled,
            default => HistoryEventType::ChildRunTerminated,
        };
        // Commit in the opposite order to the authored calls. A query must use
        // parent history, including its recorded failure order and output bytes.
        foreach ([2, 1] as $sequence) {
            $this->appendEvent($parent, $event, $payloads[$sequence] + [
                'child_status' => $status,
                'output' => Serializer::serialize('committed-child-' . $sequence),
                'payload_codec' => $parent->payload_codec,
                'exception_class' => \RuntimeException::class,
                'message' => 'committed-child-' . $sequence . '-failure',
            ]);
            if ($sequence === 2 && $status === RunStatus::Completed->value) {
                $before = $this->snapshot();
                $state = (new QueryStateReplayer())->replayState($parent->fresh());
                $this->assertInstanceOf(AllCall::class, $state->current);
                $this->assertSame([
                    'stage' => 'waiting-for-children',
                    'value' => null,
                    'failure' => null,
                ], $state->workflow->currentState());
                $this->assertSame($before, $this->snapshot());
            }
        }
        WorkflowRun::query()->where('id', '!=', $parent->id)->update([
            'status' => RunStatus::Waiting->value,
            'closed_at' => null,
            'closed_reason' => null,
            'output' => Serializer::serialize('contradictory-child-row'),
        ]);
        $expected = [
            'stage' => 'waiting-for-finish',
            'value' => null,
            'failure' => null,
        ];
        if ($status === RunStatus::Completed->value) {
            $expected['value'] = ['committed-child-1', 'committed-child-2'];
        } elseif ($status === RunStatus::Failed->value) {
            $expected['failure'] = 'committed-child-2-failure';
        } else {
            $expected['failure'] = 'Child workflow ' . $payloads[2]['child_workflow_run_id'] . ' closed as ' . $status . '.';
        }
        $before = $this->snapshot();
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($parent->fresh());
            $this->assertInstanceOf(SignalCall::class, $state->current);
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($expected, (new QueryStateReplayer())->query($parent->fresh(), 'currentState'));
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function terminalChildren(): array
    {
        $cases = [];
        foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated] as $status) {
            foreach ([false, true] as $started) {
                $cases[$status->value . ($started ? ' after start' : ' after schedule')] = [$status->value, $started];
            }
        }

        return $cases;
    }

    #[DataProvider('mixedWaitOutcomes')]
    public function testChildGroupsRestoreMixedWaitsFromCommittedHistory(string $outcome): void
    {
        $run = $this->createRun(TestQueryChildMixedWaitWorkflow::class, RunStatus::Waiting, []);
        $group = static fn (int $sequence): array => [
            'sequence' => $sequence,
            'parallel_group_id' => 'parallel-calls:1:4',
            'parallel_group_kind' => 'mixed',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 4,
            'parallel_group_index' => $sequence - 1,
        ];
        $child = $group(1) + [
            'child_workflow_type' => TestTimerWorkflow::class,
        ];
        $this->appendEvent($run, HistoryEventType::ChildWorkflowScheduled, $child);
        $this->appendEvent($run, HistoryEventType::TimerScheduled, $group(2) + [
            'timer_kind' => 'durable_timer',
            'timer_id' => 'mixed-timer',
            'delay_seconds' => 60,
        ]);
        $signal = $group(3) + [
            'signal_name' => 'approval',
            'signal_wait_id' => 'mixed-approval',
        ];
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, $signal);
        $condition = $group(4) + [
            'condition_key' => 'approval.ready',
            'condition_wait_id' => 'mixed-condition',
        ];
        $this->appendEvent($run, HistoryEventType::ConditionWaitOpened, $condition);
        $this->appendEvent($run, HistoryEventType::ChildRunCompleted, $child + [
            'child_status' => RunStatus::Completed->value,
            'output' => Serializer::serializeWithCodec($run->payload_codec, 'recorded-child'),
            'payload_codec' => $run->payload_codec,
        ]);
        $this->appendEvent($run, HistoryEventType::TimerFired, $group(2) + [
            'timer_kind' => 'durable_timer',
        ]);
        if ($outcome === 'fulfilled') {
            $this->appendEvent($run, HistoryEventType::SignalApplied, $signal + [
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, ['recorded-approval']),
                'payload_codec' => $run->payload_codec,
            ]);
            $this->appendEvent($run, HistoryEventType::ConditionWaitSatisfied, $condition);
        } elseif ($outcome === 'timed-out') {
            $this->appendEvent($run, HistoryEventType::TimerFired, $signal + [
                'timer_kind' => 'signal_timeout',
            ]);
            $this->appendEvent($run, HistoryEventType::ConditionWaitTimedOut, $condition);
        }
        $expected = [
            'stage' => 'waiting-for-mixed-group',
            'value' => null,
        ];
        if ($outcome !== 'pending') {
            $expected = [
                'stage' => 'waiting-for-finish',
                'value' => [
                    'recorded-child', true,
                    $outcome === 'fulfilled' ? 'recorded-approval' : null,
                    $outcome === 'fulfilled',
                ],
            ];
        }
        $before = $this->snapshot();
        foreach ([1, 2] as $repetition) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertInstanceOf($outcome === 'pending' ? AllCall::class : SignalCall::class, $state->current);
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function mixedWaitOutcomes(): array
    {
        return [
            'pending' => ['pending'],
            'fulfilled' => ['fulfilled'],
            'timed out' => ['timed-out'],
        ];
    }

    /**
     * @param class-string<\Workflow\V2\Workflow> $workflow
     */
    private function createRun(string $workflow, RunStatus $status, array $arguments): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'query-child-authority-' . strtolower((string) Str::ulid()),
            'workflow_class' => $workflow,
            'workflow_type' => $workflow,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflow,
            'workflow_type' => $workflow,
            'status' => $status->value,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serialize($arguments),
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

    private function appendEvent(WorkflowRun $run, HistoryEventType $type, array $payload): void
    {
        $run->increment('last_history_sequence');
        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $run->last_history_sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => now()
                ->subMinute()
                ->addSeconds($run->last_history_sequence),
        ]);
    }

    private function snapshot(): array
    {
        return array_map(static fn (string $model): array => $model::query()->orderBy('id')->get()->toArray(), [
            WorkflowRun::class, WorkflowInstance::class, WorkflowHistoryEvent::class, WorkflowLink::class,
            WorkflowCommand::class, WorkflowTask::class, ActivityExecution::class, WorkflowTimer::class,
            WorkflowSignal::class,
        ]);
    }
}
