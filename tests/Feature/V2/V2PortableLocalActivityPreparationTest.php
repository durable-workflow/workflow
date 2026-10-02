<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\PreparedLocalActivityGroupTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    public function testPreparationPersistsAWorkerAliasAndOriginalClaimBeforeAnyApplicationInvocation(): void
    {
        [$run, $task] = $this->newClaim();
        $taskBefore = $task->getAttributes();
        $reply = $this->prepare($task);
        $this->assertTrue($reply['prepared']);
        $this->assertFalse($reply['duplicate']);
        $this->assertSame('sdk-local-attempt', $reply['worker_attempt_id']);
        $this->assertSame('portable-worker', $reply['lease_owner']);
        $this->assertSame(1, $reply['workflow_task_attempt']);
        $execution = ActivityExecution::query()->findOrFail($reply['activity_execution_id']);
        $this->assertSame('python-local-greeting', $execution->activity_type);
        $this->assertSame(['Taylor'], $execution->activityArguments());
        $this->assertSame('avro', $execution->payload_codec);
        $this->assertSame(ActivityStatus::Running, $execution->status);
        $this->assertSame($reply['activity_attempt_id'], $execution->current_attempt_id);
        $attempt = $execution->attempts()
            ->sole();
        $this->assertSame(ActivityAttemptStatus::Running, $attempt->status);
        $this->assertSame($task->id, $attempt->workflow_task_id);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $events = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertSame(
            [HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted],
            $events->pluck('event_type')
                ->all()
        );
        $started = $events->last();
        $this->assertSame($task->id, $started->payload['task']['id']);
        $this->assertSame(1, $started->payload['task']['attempt_count']);
        $this->assertSame('portable-worker', $started->payload['task']['lease_owner']);
        $this->assertSame('sdk-local-attempt', $started->payload['activity_attempt']['worker_attempt_id']);
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        foreach (HistoryTimeline::forRun($run->fresh()) as $entry) {
            $this->assertSame('workflow', $entry['task']['type']);
            $this->assertSame('leased', $entry['task']['status']);
        }
    }

    public function testLostPreparationResponseReturnsTheOriginalAttemptWithoutRenewingAnyDeadline(): void
    {
        [, $task] = $this->newClaim();
        $first = $this->prepare($task, [
            'start_to_close_timeout' => 10,
            'schedule_to_close_timeout' => 20,
        ]);
        $this->assertTrue($first['prepared']);
        $taskBefore = $task->refresh()
            ->getAttributes();
        Carbon::setTestNow(now()->addSecond());
        try {
            $second = $this->prepare($task, [
                'start_to_close_timeout' => 10,
                'schedule_to_close_timeout' => 20,
            ]);
            $this->assertTrue($second['prepared']);
            $this->assertTrue($second['duplicate']);
            foreach (['activity_execution_id', 'activity_attempt_id', 'worker_attempt_id', 'workflow_task_id',
                'workflow_task_attempt', 'lease_owner', 'lease_expires_at', 'start_to_close_deadline_at',
                'schedule_to_close_deadline_at'] as $field) {
                $this->assertSame($first[$field], $second[$field]);
            }
            $this->assertSame(1, ActivityExecution::query()->count());
            $this->assertSame(2, WorkflowHistoryEvent::query()->count());
            $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('changedDescriptors')]
    public function testAPreparedAttemptCannotBeRelabelledByARetry(array $change): void
    {
        [, $task] = $this->newClaim();
        $this->assertTrue($this->prepare($task)['prepared']);
        $reply = $this->prepare($task, $change);
        $this->assertFalse($reply['prepared']);
        $this->assertSame('local_activity_preparation_mismatch', $reply['reason']);
        $this->assertSame(1, ActivityExecution::query()->count());
        $this->assertSame(2, WorkflowHistoryEvent::query()->count());
    }

    public static function changedDescriptors(): iterable
    {
        yield 'different alias' => [[
            'activity_type' => 'another-local-activity',
        ]];
        yield 'different input' => [[
            'arguments' => Serializer::serializeWithCodec('avro', ['another-input']),
        ]];
        yield 'different timeout' => [[
            'start_to_close_timeout' => 2,
        ]];
        yield 'different retry budget' => [[
            'retry_policy' => [
                'max_attempts' => 3,
            ],
        ]];
    }

    public function testChangedOwnerOrClaimAttemptCannotReuseTheOriginalPreparation(): void
    {
        [, $task] = $this->newClaim();
        $this->assertTrue($this->prepare($task)['prepared']);
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 2,
        ])->save();
        $taskBefore = $task->getAttributes();
        $this->assertSame('workflow_claim_mismatch', $this->prepare($task)['reason']);
        $reply = PortableLocalActivityPreparation::prepare(
            $task->id,
            'replacement-worker',
            2,
            1,
            'sdk-local-attempt',
            $this->descriptor(),
            '1.20',
        );
        $this->assertFalse($reply['prepared']);
        $this->assertSame('local_activity_preparation_mismatch', $reply['reason']);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $this->assertSame(2, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('invalidDescriptors')]
    public function testInvalidOrTerminalReportsCannotPretendToPrepareACallback(array $change): void
    {
        [, $task] = $this->newClaim();
        $reply = $this->prepare($task, $change);
        $this->assertFalse($reply['prepared']);
        $this->assertSame('invalid_local_activity_preparation', $reply['reason']);
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
    }

    public static function invalidDescriptors(): iterable
    {
        yield 'queued routing' => [[
            'queue' => 'another-queue',
        ]];
        yield 'outcome supplied before execution' => [[
            'outcome' => 'completed',
        ]];
        yield 'attempt report supplied before execution' => [[
            'attempts' => [],
        ]];
        yield 'negative timeout' => [[
            'heartbeat_timeout' => -1,
        ]];
        yield 'inconsistent timeouts' => [[
            'start_to_close_timeout' => 3,
            'schedule_to_close_timeout' => 2,
        ]];
        yield 'wrong execution mode' => [[
            'execution_mode' => 'remote',
        ]];
        yield 'invalid identifier encoding' => [[
            'activity_type' => "invalid-\xFF",
        ]];
    }

    public function testThePublishedProtocolDoesNotOptInToTheCandidatePreparationPath(): void
    {
        [, $task] = $this->newClaim();
        $reply = PortableLocalActivityPreparation::prepare(
            $task->id,
            'portable-worker',
            1,
            1,
            'sdk-local-attempt',
            $this->descriptor(),
        );
        $this->assertFalse($reply['prepared']);
        $this->assertSame('local_activity_preparation_requires_protocol_1_20', $reply['reason']);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
    }

    public function testUncommittedEarlierCommandsCannotBeSkipped(): void
    {
        [$run, $task] = $this->newClaim();
        $reply = PortableLocalActivityPreparation::prepare(
            $task->id,
            'portable-worker',
            1,
            2,
            'sdk-local-attempt',
            $this->descriptor(),
            '1.20',
        );
        $this->assertFalse($reply['prepared']);
        $this->assertSame('local_activity_command_prefix_not_recorded', $reply['reason']);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
            'sequence' => 1,
            'result' => Serializer::serializeWithCodec('avro', 'already-recorded'),
        ], $task);
        $reply = PortableLocalActivityPreparation::prepare(
            $task->id,
            'portable-worker',
            1,
            2,
            'sdk-local-attempt',
            $this->descriptor(),
            '1.20',
        );
        $this->assertTrue($reply['prepared']);
        $this->assertSame(2, ActivityExecution::query()->sole()->sequence);
        $this->assertSame(3, WorkflowHistoryEvent::query()->count());
    }

    public function testCancellationAfterALostResponseCannotAuthorizeApplicationInvocation(): void
    {
        [$run, $task] = $this->newClaim();
        $first = $this->prepare($task);
        $this->assertTrue($first['prepared']);
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->requestCancellation('maintenance', 30)->accepted()
        );
        $run->refresh();
        $deadline = $run->cancellation_deadline_at->toISOString();
        $before = $run->historyEvents()
            ->count();
        $reply = $this->prepare($task);
        $this->assertFalse($reply['prepared']);
        $this->assertSame('cancellation_requested', $reply['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
        $execution = ActivityExecution::query()->findOrFail($first['activity_execution_id']);
        ActivityCancellation::record($run, $execution, command: $run->cancellation_request_command_id);
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 2,
        ])->save();
        $taskBefore = $task->getAttributes();
        $receipt = ActivityCancellationAcknowledgement::recordLocalStopped(
            $first['activity_attempt_id'],
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertTrue($receipt['acknowledged']);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
    }

    public function testAnExpiredAttemptOrWorkflowClaimCannotBePreparedAgain(): void
    {
        [, $task] = $this->newClaim();
        $this->assertTrue($this->prepare($task, [
            'start_to_close_timeout' => 1,
        ])['prepared']);
        Carbon::setTestNow(now()->addSeconds(2));
        try {
            $reply = $this->prepare($task, [
                'start_to_close_timeout' => 1,
            ]);
            $this->assertFalse($reply['prepared']);
            $this->assertSame('local_activity_deadline_expired', $reply['reason']);
            $task->forceFill([
                'lease_expires_at' => now()
                    ->subSecond(),
            ])->save();
            $this->assertSame('workflow_claim_expired', $this->prepare($task)['reason']);
            $this->assertSame(2, WorkflowHistoryEvent::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function testCompleteChildLocalGroupIsCommittedBeforeAnyLocalAttempt(): void
    {
        [$run, $task] = $this->newClaim();
        $lease = $task->lease_expires_at;
        $commands = $this->groupCommands();
        $reply = $this->groupCheckpoint($task, $commands);
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $this->assertSame(3, $reply['next_sequence']);
        $this->assertCount(1, $reply['local_activities']);
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
        $this->assertEquals($lease, $task->lease_expires_at);
        $execution = ActivityExecution::query()->sole();
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(0, $execution->attempt_count);
        $this->assertNull($execution->current_attempt_id);
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->sole();
        $this->assertGroupPath($commands[1]['parallel_group_path'], $scheduled->payload['parallel_group_path']);
        $this->assertSame(1, $scheduled->payload['local_group_admission']['version']);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityStarted)->count());
        $prepared = $this->prepareGroupMember($task, $commands[1], 2);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertSame($execution->id, $prepared['activity_execution_id']);
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->sole();
        $this->assertGroupPath($commands[1]['parallel_group_path'], $started->payload['parallel_group_path']);
        $this->assertSame($task->id, $started->workflow_task_id);
    }

    public function testGroupResponseLossCannotCreateAnotherChildOrExtendTheTotalBudget(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $commands[1]['schedule_to_close_timeout'] = 20;
        $first = $this->groupCheckpoint($task, $commands);
        $this->assertTrue($first['checkpointed'], $first['reason'] ?? '');
        $deadline = ActivityExecution::query()->sole()->schedule_to_close_deadline_at;
        $events = $run->historyEvents()
            ->count();
        Carbon::setTestNow(now()->addSeconds(5));
        try {
            $duplicate = $this->groupCheckpoint($task, $commands);
            $this->assertTrue($duplicate['duplicate']);
            $this->assertEquals([
                ...$first,
                'duplicate' => true,
            ], $duplicate);
            $this->assertSame(2, WorkflowRun::query()->count());
            $this->assertSame($events, $run->historyEvents()->count());
            $prepared = $this->prepareGroupMember($task, $commands[1], 2);
            $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
            $this->assertEquals($deadline, new Carbon($prepared['schedule_to_close_deadline_at']));
            $this->assertTrue($this->prepareGroupMember($task, $commands[1], 2)['duplicate']);
            $this->assertSame(1, ActivityAttempt::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testReplacementBeforeLocalPreparationCreatesOnlyItsOwnFirstAttempt(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $this->assertSame('workflow_claim_mismatch', $this->prepareGroupMember($task, $commands[1], 2)['reason']);
        $prepared = $this->prepareGroupMember($task, $commands[1], 2, 'replacement', 2);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertSame(2, $prepared['workflow_task_attempt']);
        $this->assertSame(1, $prepared['attempt_number']);
        $this->assertSame(1, ActivityAttempt::query()->count());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
    }

    public function testCancellationBetweenGroupCommitAndPrepareRefusesTheUnstartedCallback(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        $run->forceFill([
            'cancellation_request_command_id' => 'cancellation',
            'cancellation_deadline_at' => now()
                ->addSeconds(30),
        ])->save();
        $this->assertSame('cancellation_requested', $this->prepareGroupMember($task, $commands[1], 2)['reason']);
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityStarted)->count());
    }

    public function testElapsedAdmissionTotalBudgetRecordsTimeoutWithoutFabricatingAnAttempt(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $commands[1]['schedule_to_close_timeout'] = 2;
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        Carbon::setTestNow(now()->addSeconds(2));
        try {
            $reply = $this->prepareGroupMember($task, $commands[1], 2);
            $this->assertSame('local_activity_deadline_expired', $reply['reason']);
            $this->assertSame('ActivityTimedOut', $reply['event_type']);
            $this->assertSame(0, ActivityAttempt::query()->count());
            $this->assertSame(ActivityStatus::Failed, ActivityExecution::query()->sole()->status);
            $this->assertSame(
                1,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityTimedOut)->count()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testPreparedGroupOutcomePreservesTheAuthoredPathAndOriginalClaim(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        $prepared = $this->prepareGroupMember($task, $commands[1], 2);
        $reply = app(DefaultWorkflowTaskBridge::class)->recordLocalActivityOutcome(
            $prepared['activity_attempt_id'],
            'portable-worker',
            1,
            [
                'outcome' => 'completed',
                'result' => Serializer::serializeWithCodec('avro', 'done'),
                'payload_codec' => 'avro',
            ],
            '1.20'
        );
        $this->assertTrue($reply['recorded'], $reply['reason'] ?? '');
        $this->assertFalse($reply['claim_released']);
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCompleted)->sole();
        $this->assertGroupPath($commands[1]['parallel_group_path'], $event->payload['parallel_group_path']);
        $this->assertSame($task->id, $event->payload['task']['id']);
        $this->assertSame($prepared['activity_attempt_id'], $event->payload['activity_attempt_id']);
    }

    #[DataProvider('invalidGroupChanges')]
    public function testInvalidGroupCreatesNeitherTheChildNorTheLocalExecution(string $change): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        match ($change) {
            'partial' => array_pop($commands),
            'missing child' => array_shift($commands),
            'wrong member index' => $commands[1]['parallel_group_path'][0]['parallel_group_index'] = 0,
            'wrong group size' => $commands[1]['parallel_group_size'] = 3,
            'outcome instead of admission' => $commands[1]['type'] = 'record_local_activity',
            'routing' => $commands[1]['queue'] = 'remote',
            'unshielded proof' => $commands[1]['cancellation_cleanup'] = [
                'request_id' => 'invented',
                'delivery_history_event_id' => 'invented',
            ],
        };
        $reply = $this->groupCheckpoint($task, $commands);
        $this->assertFalse($reply['checkpointed']);
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(0, $run->historyEvents()->count());
    }

    public static function invalidGroupChanges(): iterable
    {
        foreach (['partial', 'missing child', 'wrong member index', 'wrong group size',
            'outcome instead of admission', 'routing', 'unshielded proof'] as $change) {
            yield $change => [$change];
        }
    }

    public function testChangedGroupOrPreparationCannotRelabelTheAdmittedWork(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        $before = $run->historyEvents()
            ->count();
        $commands[1]['activity_type'] = 'changed';
        $this->assertSame('local_activity_checkpoint_mismatch', $this->groupCheckpoint($task, $commands)['reason']);
        $this->assertSame(
            'local_activity_group_admission_mismatch',
            $this->prepareGroupMember($task, $commands[1], 2)['reason']
        );
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
    }

    public function testIndividualPreparationCannotCreateAPartialGroup(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $local = [...$commands[1], ...ParallelChildGroup::itemMetadata(1, 2, 0, 'mixed')];
        $this->assertSame('local_activity_group_not_admitted', $this->prepareGroupMember($task, $local, 1)['reason']);
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, $run->historyEvents()->count());
    }

    public function testDatabaseObjectKeyOrderingPreservesGroupAdmissionButChangedScalarTypesDoNot(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $this->assertTrue($this->groupCheckpoint($task, $commands)['checkpointed']);
        $execution = ActivityExecution::query()->sole();
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->sole();
        $path = $execution->parallel_group_path;
        krsort($path[0]);
        $payload = $scheduled->payload;
        $payload['parallel_group_path'] = $path;
        $scheduled->forceFill([
            'payload' => $payload,
        ])->save();
        $path[0]['parallel_group_size'] = '2';
        $execution->forceFill([
            'parallel_group_path' => $path,
        ])->save();
        $this->assertSame(
            'local_activity_group_admission_mismatch',
            $this->prepareGroupMember($task, $commands[1], 2)['reason']
        );
        $this->assertSame(0, ActivityAttempt::query()->count());
        $path[0]['parallel_group_size'] = 2;
        $execution->forceFill([
            'parallel_group_path' => $path,
        ])->save();
        $reply = $this->prepareGroupMember($task, $commands[1], 2);
        $this->assertTrue($reply['prepared'], $reply['reason'] ?? '');
        $this->assertSame(1, ActivityAttempt::query()->count());
    }

    public function testGroupRoleIsOptionalAndPublishedProtocolCannotUseIt(): void
    {
        [, $task] = $this->newClaim();
        $bridge = app(PreparedLocalActivityGroupTaskBridge::class);
        $this->assertSame(app(WorkflowTaskBridge::class), $bridge);
        $reply = $bridge->checkpointLocalActivityGroup(
            $task->id,
            'portable-worker',
            1,
            'candidate-only',
            1,
            $this->groupCommands()
        );
        $this->assertFalse($reply['checkpointed']);
        foreach ([WorkflowTaskBridge::class, PreparedLocalActivityTaskBridge::class] as $contract) {
            $custom = \Mockery::mock($contract);
            $this->app->instance(WorkflowTaskBridge::class, $custom);
            $this->assertSame($custom, app(PreparedLocalActivityGroupTaskBridge::class));
            $this->assertNotInstanceOf(PreparedLocalActivityGroupTaskBridge::class, $custom);
        }
    }

    public function testNestedAllGroupsCommitEveryLeafAndPreserveEachCompletePath(): void
    {
        [$run, $task] = $this->newClaim();
        $commands = $this->groupCommands();
        $commands[0] = [...$commands[0], ...ParallelChildGroup::itemMetadata(1, 3, 0, 'mixed')];
        for ($offset = 0; $offset < 2; ++$offset) {
            $commands[$offset + 1] = [
                ...$this->descriptor(),
                'type' => 'prepare_local_activity',
                ...ParallelChildGroup::payloadForPath([
                    ParallelChildGroup::groupEntry(1, 3, $offset + 1, 'mixed'),
                    ParallelChildGroup::groupEntry(2, 2, $offset, 'activity'),
                ]),
            ];
        }
        $reply = $this->groupCheckpoint($task, $commands);
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $this->assertCount(2, $reply['local_activities']);
        $this->assertSame(4, $reply['next_sequence']);
        $this->assertSame(0, ActivityAttempt::query()->count());
        foreach ([1, 2] as $index) {
            $prepared = $this->prepareGroupMember($task, $commands[$index], $index + 1);
            $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
            $started = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)
                ->where('payload->activity_attempt_id', $prepared['activity_attempt_id'])->sole();
            $this->assertGroupPath($commands[$index]['parallel_group_path'], $started->payload['parallel_group_path']);
        }
    }

    public function testLocalFirstGroupStillCommitsTheChildBeforeReturningAdmission(): void
    {
        [$run, $task] = $this->newClaim();
        $original = $this->groupCommands();
        $commands = [[...$original[1], ...ParallelChildGroup::itemMetadata(1, 2, 0, 'mixed')],
            [...$original[0], ...ParallelChildGroup::itemMetadata(1, 2, 1, 'mixed')]];
        $reply = $this->groupCheckpoint($task, $commands);
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(1, ActivityExecution::query()->sole()->sequence);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowScheduled)->count()
        );
    }

    public function testAdmissionRollsBackTheChildIfALaterLocalStructuralLimitFails(): void
    {
        [$run, $task] = $this->newClaim();
        config()
            ->set('workflows.v2.structural_limits.pending_activity_count', 1);
        $commands = $this->groupCommands();
        $commands[0] = [...$commands[0], ...ParallelChildGroup::itemMetadata(1, 3, 0, 'mixed')];
        $commands[1] = [...$commands[1], ...ParallelChildGroup::itemMetadata(1, 3, 1, 'mixed')];
        $commands[2] = [...$commands[1], ...ParallelChildGroup::itemMetadata(1, 3, 2, 'mixed')];
        try {
            $this->groupCheckpoint($task, $commands);
            $this->fail('The second local member should exceed the structural limit.');
        } catch (\Workflow\V2\Exceptions\StructuralLimitExceededException $exception) {
            $this->assertStringContainsString('pending', $exception->getMessage());
        }
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, $run->historyEvents()->count());
    }

    /** @param list<array<string, mixed>> $expected
     * @param list<array<string, mixed>> $actual
     */
    private function assertGroupPath(array $expected, array $actual): void
    {
        foreach ($expected as &$entry) {
            ksort($entry);
        }
        unset($entry);
        foreach ($actual as &$entry) {
            ksort($entry);
        }
        unset($entry);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return list<array{type: string, ...}>
     */
    private function groupCommands(): array
    {
        return [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'python-child',
            'arguments' => Serializer::serializeWithCodec('avro', ['child']),
            'payload_codec' => 'avro',
            ...ParallelChildGroup::itemMetadata(1, 2, 0, 'mixed'),
        ], [
            ...$this->descriptor(),
            'type' => 'prepare_local_activity',
            ...ParallelChildGroup::itemMetadata(1, 2, 1, 'mixed'),
        ]];
    }

    /** @param list<array{type: string, ...}> $commands
     * @return array<string, mixed>
     */
    private function groupCheckpoint(WorkflowTask $task, array $commands): array
    {
        return app(PreparedLocalActivityGroupTaskBridge::class)->checkpointLocalActivityGroup(
            $task->id,
            'portable-worker',
            1,
            'group-one',
            1,
            $commands,
            '1.20'
        );
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function prepareGroupMember(
        WorkflowTask $task,
        array $descriptor,
        int $sequence,
        string $owner = 'portable-worker',
        int $epoch = 1
    ): array {
        return PortableLocalActivityPreparation::prepare(
            $task->id,
            $owner,
            $epoch,
            $sequence,
            'group-local-attempt',
            [
                ...$descriptor,
                'type' => 'record_local_activity',
            ],
            '1.20'
        );
    }

    private function descriptor(): array
    {
        return [
            'type' => 'record_local_activity',
            'activity_type' => 'python-local-greeting',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
        ];
    }

    /** @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function prepare(WorkflowTask $task, array $change = []): array
    {
        return PortableLocalActivityPreparation::prepare(
            $task->id,
            'portable-worker',
            1,
            1,
            'sdk-local-attempt',
            [...$this->descriptor(), ...$change],
            '1.20',
        );
    }

    /**
     * @return array{WorkflowRun, WorkflowTask}
     */
    private function newClaim(): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 1,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'portable-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return [$run, $task->refresh()];
    }
}
