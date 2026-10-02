<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-02T06:24:00.000000Z');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testCleanupIsPreparedAfterDeliveryAndCompletesBeforeTheOriginalDeadline(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $prepared = $this->prepare($task, $descriptor);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $deadline = $run->cancellation_deadline_at->toISOString();
        $this->assertSame($deadline, $prepared['start_to_close_deadline_at']);
        $this->assertSame($deadline, $prepared['schedule_to_close_deadline_at']);
        $this->assertSame($run->cancellation_request_command_id, $prepared['cancellation_cleanup']['root_request_id']);
        $this->assertSame(
            $descriptor['cancellation_cleanup']['delivery_history_event_id'],
            $prepared['cancellation_cleanup']['delivery_history_event_id']
        );
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->sole();
        $this->assertSame(
            $prepared['cancellation_cleanup'],
            $started->payload['local_preparation']['cancellation_cleanup']
        );
        $this->assertTrue(
            $this->bridge()
                ->controlLocalActivity($prepared['activity_attempt_id'], 'owner', 7, true, '1.20')['active']
        );
        $result = $this->outcome($prepared['activity_attempt_id']);
        $this->assertTrue($result['recorded'], $result['reason'] ?? '');
        $this->assertFalse($result['claim_released']);
        $this->assertTrue($this->bridge()->complete($task->id, [[
            'type' => 'complete_workflow',
            'output' => Serializer::serializeWithCodec('avro', 'cleaned'),
            'payload_codec' => 'avro',
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame($deadline, $run->cancellation_deadline_at->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
        );
    }

    public function testCancellationBeforeDeliveryCannotAdmitCleanupOrCheckpointItsPrefix(): void
    {
        [$run, $task] = $this->newClaim();
        WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30);
        $descriptor = $this->descriptor();
        $descriptor['cancellation_cleanup'] = [
            'request_id' => $run->refresh()
->cancellation_request_command_id,
            'delivery_history_event_id' => 'not-delivered',
        ];
        $this->assertSame('local_activity_cleanup_authority_mismatch', $this->prepare($task, $descriptor)['reason']);
        $this->assertSame('cancellation_requested', $this->bridge()->checkpointLocalActivityPrefix(
            $task->id,
            'owner',
            7,
            'before-delivery',
            1,
            [],
            '1.20'
        )['reason']);
        $this->assertSame(0, ActivityExecution::query()->count());
    }

    public function testHistoryRecordingMayFollowTheDeliveryStateTimestamp(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $delivery = $run->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole();
        $delivery->forceFill([
            'recorded_at' => $run->cancellation_delivered_at->copy()->addMicroseconds(200),
        ])->save();
        Carbon::setTestNow(now()->addMillisecond());
        $prepared = $this->prepare($task, $descriptor);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertTrue(
            $this->bridge()->controlLocalActivity($prepared['activity_attempt_id'], 'owner', 7, false, '1.20')['active']
        );
        $this->assertTrue($this->outcome($prepared['activity_attempt_id'])['recorded']);
    }

    #[DataProvider('invalidProofs')]
    public function testCleanupAdmissionRejectsChangedOrInventedAuthority(string $mutation, string $reason): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $sequence = 2;
        match ($mutation) {
            'missing' => $descriptor = $this->descriptor(),
            'wrong request' => $descriptor['cancellation_cleanup']['request_id'] = 'another-request',
            'wrong delivery' => $descriptor['cancellation_cleanup']['delivery_history_event_id'] = 'another-delivery',
            'client deadline' => $descriptor['cancellation_cleanup']['cleanup_deadline_at'] = now()->addHour()->toISOString(),
            'before boundary' => $sequence = 1,
            'changed root budget' => $run->forceFill([
                'cancellation_deadline_at' => now()
                    ->addHour(),
            ])->save(),
            'expired root budget' => Carbon::setTestNow(now()->addSeconds(30)),
        };
        if ($mutation === 'expired root budget') {
            $task->forceFill([
                'lease_expires_at' => now()
                    ->addSeconds(10),
            ])->save();
        }
        $reply = $this->prepare($task, $descriptor, sequence: $sequence);
        $this->assertFalse($reply['prepared']);
        $this->assertSame($reason, $reply['reason']);
        $this->assertSame(0, ActivityExecution::query()->count());
    }

    public static function invalidProofs(): iterable
    {
        yield 'explicit shielding required' => ['missing', 'cancellation_requested'];
        yield 'local request identity' => ['wrong request', 'local_activity_cleanup_authority_mismatch'];
        yield 'canonical delivery identity' => ['wrong delivery', 'local_activity_cleanup_authority_mismatch'];
        yield 'runtime owns deadline' => ['client deadline', 'invalid_local_activity_preparation'];
        yield 'delivery consumes authored boundary' => ['before boundary', 'local_activity_cleanup_authority_mismatch'];
        yield 'immutable root deadline' => ['changed root budget', 'local_activity_cleanup_authority_mismatch'];
        yield 'no admission at deadline' => ['expired root budget', 'cancellation_deadline_expired'];
    }

    public function testACommittedCleanupPrefixIsReplayedBeforeLocalAdmission(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $prefix = [[
            'type' => 'record_side_effect',
            'marker_id' => 'cleanup-marker',
            'result' => Serializer::serializeWithCodec('avro', 'once'),
            'payload_codec' => 'avro',
        ]];
        $first = $this->bridge()
            ->checkpointLocalActivityPrefix($task->id, 'owner', 7, 'cleanup-prefix', 2, $prefix, '1.20');
        $this->assertTrue($first['checkpointed'], $first['reason'] ?? '');
        $this->assertSame(3, $first['next_sequence']);
        $this->assertTrue($this->bridge()->checkpointLocalActivityPrefix(
            $task->id,
            'owner',
            7,
            'cleanup-prefix',
            2,
            $prefix,
            '1.20'
        )['duplicate']);
        $this->assertTrue($this->prepare($task, $descriptor, sequence: 3)['prepared']);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::SideEffectRecorded)->count());
        $this->assertSame(3, ActivityExecution::query()->sole()->sequence);
    }

    public function testOnlyAnApplicationHeartbeatMovesTheHeartbeatDeadline(): void
    {
        [$run, $task] = $this->newClaim();
        $prepared = $this->prepare($task, [
            ...$this->descriptor(),
            'heartbeat_timeout' => 3,
        ], sequence: 1);
        $this->assertTrue($prepared['prepared']);
        $attempt = ActivityAttempt::query()->findOrFail($prepared['activity_attempt_id']);
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $lease = $attempt->lease_expires_at->toISOString();
        $fixed = [
            $execution->close_deadline_at->toISOString(),
            $execution->schedule_to_close_deadline_at->toISOString(),
        ];
        Carbon::setTestNow(now()->addSeconds(2));
        $heartbeat = $this->bridge()
            ->heartbeatLocalActivity($attempt->id, 'owner', 7, [
                'message' => 'step complete',
            ], '1.20');
        $this->assertTrue($heartbeat['active']);
        $this->assertTrue($heartbeat['heartbeat_recorded']);
        $this->assertFalse($heartbeat['renewed']);
        $this->assertSame($lease, $heartbeat['lease_expires_at']);
        $this->assertSame($lease, $task->refresh()->lease_expires_at->toISOString());
        $this->assertSame(
            now()
                ->addSeconds(3)
                ->toISOString(),
            $execution->refresh()
                ->heartbeat_deadline_at->toISOString()
        );
        $this->assertSame(
            $fixed,
            [$execution->close_deadline_at->toISOString(), $execution->schedule_to_close_deadline_at->toISOString()]
        );
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->sole();
        $this->assertSame([
            'message' => 'step complete',
        ], $event->payload['progress']);
        $this->assertTrue($event->payload['local_activity']);
        $this->assertSame($event->id, $heartbeat['heartbeat_history_event_id']);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertTrue($this->bridge()->controlLocalActivity($attempt->id, 'owner', 7, true, '1.20')['active']);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count()
        );
    }

    public function testAnApplicationHeartbeatCannotReviveAnExpiredTimeout(): void
    {
        [, $task] = $this->newClaim();
        $prepared = $this->prepare($task, [
            ...$this->descriptor(),
            'heartbeat_timeout' => 3,
        ], sequence: 1);
        Carbon::setTestNow(now()->addSeconds(3));
        $reply = $this->bridge()
            ->heartbeatLocalActivity($prepared['activity_attempt_id'], 'owner', 7, [], '1.20');
        $this->assertSame('local_activity_deadline_expired', $reply['reason']);
        $this->assertFalse($reply['heartbeat_recorded']);
    }

    public function testCleanupHeartbeatsAndSupervisorRenewalsCannotExtendTheRootBudget(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $descriptor['heartbeat_timeout'] = 60;
        $prepared = $this->prepare($task, $descriptor);
        $deadline = $run->cancellation_deadline_at->toISOString();
        for ($i = 0; $i < 3; ++$i) {
            Carbon::setTestNow(now()->addSeconds(9));
            $control = $this->bridge()
                ->controlLocalActivity($prepared['activity_attempt_id'], 'owner', 7, true, '1.20');
            $this->assertTrue($control['active']);
        }
        $this->assertSame($deadline, $control['lease_expires_at']);
        $this->assertSame($deadline, $control['workflow_lease_expires_at']);
        $heartbeat = $this->bridge()
            ->heartbeatLocalActivity($prepared['activity_attempt_id'], 'owner', 7, [], '1.20');
        $this->assertTrue($heartbeat['heartbeat_recorded']);
        foreach (['heartbeat_deadline_at', 'start_to_close_deadline_at', 'schedule_to_close_deadline_at'] as $field) {
            $this->assertSame($deadline, $heartbeat[$field]);
        }
        $request = $run->cancellation_request_command_id;
        $this->assertTrue(WorkflowStub::loadRun($run->id)->requestCancellation('duplicate', 600)->accepted());
        $this->assertSame($request, $run->refresh()->cancellation_request_command_id);
        $this->assertSame($deadline, $run->cancellation_deadline_at->toISOString());
        Carbon::setTestNow(now()->addSeconds(3));
        $outcome = $this->outcome($prepared['activity_attempt_id']);
        $this->assertSame('cancellation_deadline_expired', $outcome['reason']);
        $this->assertTrue($outcome['fenced']);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $stop = $this->bridge()
            ->controlLocalActivity($prepared['activity_attempt_id'], 'owner', 7, true, '1.20');
        $this->assertSame('cancellation_deadline_expired', $stop['reason']);
        $this->assertTrue($stop['stop_required']);
        $this->assertFalse($stop['renewed']);
        $this->assertTrue($this->bridge()->acknowledgeLocalActivityCancellation(
            $prepared['activity_attempt_id'],
            'owner',
            $request,
            7,
            '1.20'
        )['acknowledged']);
    }

    public function testAReplacementRecoversCleanupWithoutRedeliveryOrAResetDeadline(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $descriptor['retry_policy'] = [
            'max_attempts' => 2,
            'backoff_seconds' => [0],
        ];
        $prepared = $this->prepare($task, $descriptor);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $deadline = $run->cancellation_deadline_at->toISOString();
        Carbon::setTestNow(now()->addSeconds(11));
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $this->assertSame('workflow_claim_mismatch', $this->bridge()->controlLocalActivity(
            $prepared['activity_attempt_id'],
            'owner',
            7,
            true,
            '1.20'
        )['reason']);
        $this->assertSame('workflow_claim_mismatch', $this->outcome($prepared['activity_attempt_id'])['reason']);
        $recovered = $this->bridge()
            ->recoverLocalActivity($task->id, 'replacement', 8, 2, $descriptor, '1.20');
        $this->assertTrue($recovered['recovered'], $recovered['reason'] ?? '');
        $this->assertSame('unknown', $recovered['callback_stop_state']);
        $retryTask = WorkflowTask::query()->findOrFail($recovered['created_task_ids'][0]);
        $retryTask->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'replacement',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $retry = $this->bridge()
            ->prepareLocalActivity($retryTask->id, 'replacement', 1, 2, 'replacement-local', $descriptor, '1.20');
        $this->assertTrue($retry['prepared'], $retry['reason'] ?? '');
        $this->assertSame(2, $retry['attempt_number']);
        $this->assertNotSame($prepared['activity_attempt_id'], $retry['activity_attempt_id']);
        $this->assertSame($prepared['cancellation_cleanup'], $retry['cancellation_cleanup']);
        $this->assertSame($deadline, $retry['start_to_close_deadline_at']);
        $this->assertSame($deadline, $retry['schedule_to_close_deadline_at']);
        $completed = $this->bridge()
            ->recordLocalActivityOutcome($retry['activity_attempt_id'], 'replacement', 1, [
                'outcome' => 'completed',
                'result' => Serializer::serializeWithCodec('avro', 'resumed cleanup'),
                'payload_codec' => 'avro',
            ], '1.20');
        $this->assertTrue($completed['recorded'], $completed['reason'] ?? '');
        $this->assertTrue($this->bridge()->complete($retryTask->id, [[
            'type' => 'complete_workflow',
            'output' => Serializer::serializeWithCodec('avro', 'cleaned'),
            'payload_codec' => 'avro',
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertTrue(now()->lt($run->cancellation_deadline_at));
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
        );
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
    }

    public function testRecoveryAndNewPrefixWorkStopAtTheOriginalDeadline(): void
    {
        [$run, $task, $descriptor] = $this->cleanupClaim();
        $prepared = $this->prepare($task, $descriptor);
        $this->assertTrue($prepared['prepared']);
        Carbon::setTestNow(now()->addSeconds(30));
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $before = $run->historyEvents()
            ->count();
        $recovery = $this->bridge()
            ->recoverLocalActivity($task->id, 'replacement', 8, 2, $descriptor, '1.20');
        $this->assertSame('cancellation_deadline_expired', $recovery['reason']);
        $this->assertFalse($recovery['recovered']);
        $this->assertSame([], $recovery['created_task_ids']);
        $prefix = $this->bridge()
            ->checkpointLocalActivityPrefix($task->id, 'replacement', 8, 'late-prefix', 3, [], '1.20');
        $this->assertSame('cancellation_deadline_expired', $prefix['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testMalformedApplicationProgressCannotRenewOrRecordHeartbeat(): void
    {
        [, $task] = $this->newClaim();
        $prepared = $this->prepare($task, $this->descriptor(), sequence: 1);
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $before = $execution->getAttributes();
        $reply = $this->bridge()
            ->heartbeatLocalActivity($prepared['activity_attempt_id'], 'owner', 7, [
                'invented' => 'field',
            ], '1.20');
        $this->assertSame('invalid_local_activity_heartbeat', $reply['reason']);
        $this->assertFalse($reply['heartbeat_recorded']);
        $this->assertFalse($reply['renewed']);
        $this->assertSame($before, $execution->refresh()->getAttributes());
    }

    public function testACompletedReceiptRemainsReadableAfterTheRootDeadline(): void
    {
        [, $task, $descriptor] = $this->cleanupClaim();
        $prepared = $this->prepare($task, $descriptor);
        $first = $this->outcome($prepared['activity_attempt_id']);
        $this->assertTrue($first['recorded']);
        Carbon::setTestNow(now()->addSeconds(31));
        $duplicate = $this->outcome($prepared['activity_attempt_id']);
        $this->assertTrue($duplicate['recorded']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['event_id'], $duplicate['event_id']);
    }

    public function testAFailedHeartbeatWriteRollsBackTheDeadlineAndHistory(): void
    {
        [, $task] = $this->newClaim();
        $prepared = $this->prepare($task, [
            ...$this->descriptor(),
            'heartbeat_timeout' => 3,
        ], sequence: 1);
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $before = $execution->getAttributes();
        $event = 'eloquent.saving: ' . ActivityAttempt::class;
        Event::listen($event, static function (ActivityAttempt $saved): void {
            if ($saved->isDirty('last_heartbeat_at')) {
                throw new RuntimeException('synthetic heartbeat write failure');
            }
        });
        Carbon::setTestNow(now()->addSecond());
        try {
            $this->bridge()
                ->heartbeatLocalActivity($prepared['activity_attempt_id'], 'owner', 7, [], '1.20');
            $this->fail('The write failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic heartbeat write failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $execution->refresh()->getAttributes());
        $this->assertSame(
            0,
            $execution->run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count()
        );
    }

    private function bridge(): PreparedLocalActivityTaskBridge
    {
        return app(PreparedLocalActivityTaskBridge::class);
    }

    private function prepare(WorkflowTask $task, array $descriptor, int $sequence = 2): array
    {
        return $this->bridge()
            ->prepareLocalActivity($task->id, 'owner', 7, $sequence, 'local-attempt', $descriptor, '1.20');
    }

    private function outcome(string $attemptId): array
    {
        return $this->bridge()
            ->recordLocalActivityOutcome($attemptId, 'owner', 7, [
                'outcome' => 'completed',
                'result' => Serializer::serializeWithCodec('avro', 'cleaned'),
                'payload_codec' => 'avro',
            ], '1.20');
    }

    private function cleanupClaim(): array
    {
        [$run, $task] = $this->newClaim();
        $this->assertTrue(WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30)->accepted());
        $run->refresh();
        $delivery = app(CooperativeWorkflowTaskBridge::class)->deliverCancellation(
            $task->id,
            $run->cancellation_request_command_id,
            1,
            'timer'
        );
        $this->assertTrue($delivery['delivered'], $delivery['reason'] ?? '');
        $descriptor = $this->descriptor();
        $descriptor['cancellation_cleanup'] = [
            'request_id' => $run->cancellation_request_command_id,
            'delivery_history_event_id' => $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole()->id,
        ];

        return [$run->refresh(), $task->refresh(), $descriptor];
    }

    private function descriptor(): array
    {
        return [
            'type' => 'record_local_activity',
            'activity_type' => 'opaque-cleanup',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'start_to_close_timeout' => 60,
            'schedule_to_close_timeout' => 120,
        ];
    }

    private function newClaim(): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 7,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'owner',
            'lease_expires_at' => now()
                ->addSeconds(10),
        ]);

        return [$run, $task];
    }
}
