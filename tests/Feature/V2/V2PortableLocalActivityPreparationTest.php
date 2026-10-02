<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\HistoryTimeline;
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
