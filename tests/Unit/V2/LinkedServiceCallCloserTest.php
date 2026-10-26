<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Jobs\RunServiceCall;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LinkedServiceCallCloser;
use Workflow\V2\Support\ServiceCallDeadline;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallView;
use Workflow\V2\Support\ServiceCallWorkflowDelivery;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class LinkedServiceCallCloserTest extends TestCase
{
    public function testRepairExpiresTheOriginalCallWithoutAnApiReadAndReplacementReplaysOneFailure(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-11T10:00:00.123456Z');
        try {
            $service = $this->createRun();
            $caller = $this->createRun('caller');
            $deadline = now()
                ->addSecond();
            $call = $this->createCall($service, [
                'caller_workflow_instance_id' => $caller->workflow_instance_id,
                'caller_workflow_run_id' => $caller->id,
                'operation_mode' => 'sync',
                'deadline_policy' => [
                    'timeout_seconds' => 1,
                ],
                'call_deadline_at' => $deadline,
            ]);
            $this->recordAdmission($caller, $call);
            Carbon::setTestNow($deadline);
            $report = TaskWatchdog::runPass('sync', 'default', runIds: [$caller->id]);
            $this->assertSame(1, $report['service_call_timeouts_enforced']);
            $this->assertSame([], $report['service_call_timeout_failures']);
            $this->assertSame('failed', $call->refresh()->status);
            $this->assertSame(ServiceCallOutcome::TimedOut, $call->outcome);
            $this->assertSame('2026-10-11T10:00:01.123456Z', ServiceCallView::listItem($call)['call_deadline_at']);
            $task = WorkflowTask::query()->where('payload->service_call_id', $call->id)->sole();
            $first = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'first-worker');
            $this->assertTrue($first['claimed']);
            $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallFailed->value)->sole();
            $this->assertSame($call->id, $event->payload['service_call_id']);
            $this->assertSame(7, $event->payload['sequence']);
            $this->assertSame('timed_out', $event->payload['service_call']['outcome']);
            $original = $event->getAttributes();
            // Recover the abandoned caller claim without an application API poll.
            $task->refresh()
                ->forceFill([
                    'lease_expires_at' => now()
                        ->subSecond(),
                ])->save();
            TaskWatchdog::runPass('sync', 'default', runIds: [$caller->id]);
            $second = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'replacement-worker');
            $this->assertTrue($second['claimed']);
            $this->assertSame($original, $event->refresh()->getAttributes());
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallFailed->value)->count());
            WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', 'late'),
                'payload_codec' => 'avro',
            ]);
            $this->assertSame(ServiceCallOutcome::TimedOut, $call->refresh()->outcome);
            $this->assertNull($call->completed_at);
            $this->assertArrayNotHasKey('nexus_result', $call->metadata);
            $this->assertSame(1, WorkflowTask::query()->where('payload->service_call_id', $call->id)->count());
            $this->assertFalse(ServiceCallDeadline::expire($call));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testExpiryWinsWhenLinkedCompletionArrivesAtTheOriginalBoundary(): void
    {
        Carbon::setTestNow('2026-10-11T10:00:00.123456Z');
        try {
            $service = $this->createRun();
            $call = $this->createCall($service, [
                'deadline_policy' => [
                    'timeout_seconds' => 1,
                ],
                'call_deadline_at' => now()
                    ->addSecond(),
            ]);
            Carbon::setTestNow(now()->addSecond());
            WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', 'boundary'),
                'payload_codec' => 'avro',
            ]);
            $this->assertSame(ServiceCallOutcome::TimedOut, $call->refresh()->outcome);
            $this->assertNull($call->completed_at);
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $service->id)
                ->where('event_type', HistoryEventType::WorkflowCompleted->value)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testACompletionCommittedBeforeDeadlineIsNotReplacedByLaterExpiry(): void
    {
        Carbon::setTestNow('2026-10-11T10:00:00.123456Z');
        try {
            $service = $this->createRun();
            $call = $this->createCall($service, [
                'deadline_policy' => [
                    'timeout_seconds' => 1,
                ],
                'call_deadline_at' => now()
                    ->addSecond(),
            ]);
            WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', 'on time'),
                'payload_codec' => 'avro',
            ]);
            Carbon::setTestNow(now()->addSeconds(2));
            $this->assertFalse(ServiceCallDeadline::expire($call->refresh()));
            $this->assertSame(ServiceCallOutcome::Completed, $call->outcome);
            $this->assertSame([], ServiceCallDeadline::expiredCallIds(10));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testMigrationBackfillsOriginalAcceptanceAndCanResumeWithoutResettingTheBudget(): void
    {
        $service = $this->createRun();
        $call = $this->createCall($service, [
            'accepted_at' => '2026-10-01 10:00:00.123456',
            'deadline_policy' => [
                'timeout_seconds' => 1,
            ],
        ]);
        $inherited = $this->createCall($service);
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_11_000100_add_original_service_call_deadlines.php';
        $migration->up();
        $original = $call->refresh()
            ->call_deadline_at->format('Y-m-d H:i:s.u');
        $this->assertSame('2026-10-01 10:00:01.123456', $original);
        $migration->up();
        $this->assertSame($original, $call->refresh()->call_deadline_at->format('Y-m-d H:i:s.u'));
        $this->assertNull($inherited->refresh()->call_deadline_at);
        $this->assertTrue(ServiceCallDeadline::expire($call));
        $this->assertSame(ServiceCallOutcome::TimedOut, $call->outcome);
    }

    public function testMigrationPreservesLegacyTimezoneWhileTheNewDeadlineRemainsUtc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $service = $this->createRun();
            $call = $this->createCall($service, [
                'accepted_at' => '2026-10-01 10:00:00.123456',
                'deadline_policy' => [
                    'timeout_seconds' => 1,
                ],
            ]);
            $migration = require __DIR__ . '/../../../src/migrations/2026_10_11_000100_add_original_service_call_deadlines.php';
            $migration->up();
            $this->assertSame(
                '2026-10-01T08:00:01.123456Z',
                ServiceCallView::listItem($call->refresh())['call_deadline_at']
            );
            $this->assertSame('2026-10-01 08:00:01.123456', $call->getRawOriginal('call_deadline_at'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testInvalidLegacyPolicyRollsBackBackfillInsteadOfInventingADeadline(): void
    {
        $service = $this->createRun();
        $valid = $this->createCall($service, [
            'deadline_policy' => [
                'timeout_seconds' => 1,
            ],
        ]);
        $invalid = $this->createCall($service, [
            'deadline_policy' => [
                'unsupported' => 1,
            ],
        ]);
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_11_000100_add_original_service_call_deadlines.php';
        try {
            $migration->up();
            $this->fail('Unsupported historical policy unexpectedly migrated.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString($invalid->id, $failure->getMessage());
        }
        $this->assertNull($valid->refresh()->call_deadline_at);
        $this->assertNull($invalid->refresh()->call_deadline_at);
        $invalid->forceFill([
            'deadline_policy' => [
                'timeout_seconds' => 2,
            ],
        ])->save();
        $migration->up();
        $this->assertEquals($valid->accepted_at->copy()->utc()->addSecond(), $valid->refresh()->call_deadline_at);
        $this->assertEquals($invalid->accepted_at->copy()->utc()->addSeconds(2), $invalid->refresh()->call_deadline_at);
    }

    public function testUnlinkedCallsRemainExpiryCandidatesInAQueueScopedBackgroundPass(): void
    {
        $service = $this->createRun();
        $call = $this->createCall($service, [
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'call_deadline_at' => now()
                ->subSecond(),
        ]);
        $this->assertSame([$call->id], ServiceCallDeadline::expiredCallIds(10, connection: 'other', queue: 'other'));
        $this->assertTrue(ServiceCallDeadline::expire($call));
        $this->assertSame(ServiceCallOutcome::TimedOut, $call->outcome);
    }

    /**
     * @param array<string, mixed> $admission
     */
    #[DataProvider('waitingAdmissionEvents')]
    public function testTerminalServiceOutcomeResumesItsOriginalWaitingCallerOnce(
        HistoryEventType $admissionType,
        array $admission,
    ): void {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $service = $this->createRun();
        $caller = $this->createRun('caller');
        $call = $this->createCall($service, [
            'caller_workflow_instance_id' => $caller->workflow_instance_id,
            'caller_workflow_run_id' => $caller->id,
            'operation_mode' => 'sync',
        ]);
        WorkflowHistoryEvent::record($caller, $admissionType, [
            'sequence' => 7,
            'service_call_id' => $call->id,
            'operation_mode' => 'sync',
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,
            'payload_codec' => 'avro',
            'caller_sdk_language' => 'sdk-python',
            'service_call' => (new DefaultServiceControlPlane())->describeCall($call->id, [
                'namespace' => 'service',
            ]),
        ] + $admission);
        $blob = Serializer::serializeWithCodec('avro', [
            'greeting' => 'hello world',
        ]);
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => $blob,
            'payload_codec' => 'avro',
        ]);

        $this->assertSame('completed', $call->refresh()->status);
        $task = WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole();
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $caller->id)
            ->where('event_type', HistoryEventType::ServiceCallCompleted->value)
            ->count());
        $claim = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'replacement-worker');
        $this->assertTrue($claim['claimed'], json_encode($claim, JSON_THROW_ON_ERROR));
        $task->refresh();
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $caller->id)
            ->where('event_type', HistoryEventType::ServiceCallCompleted->value)
            ->sole();
        $this->assertSame(7, $event->payload['sequence']);
        $this->assertSame($call->id, $event->payload['service_call_id']);
        $this->assertSame('sdk-python', $event->payload['caller_sdk_language']);
        $this->assertSameJsonObject([
            'codec' => 'avro',
            'blob' => $blob,
        ], $event->payload['response_payload']);
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame('caller', $task->namespace);
        $this->assertSame($event->id, $task->payload['workflow_history_event_id']);
        $this->assertSame($call->id, $task->payload['service_call_id']);
        $this->assertSame(7, $task->payload['workflow_sequence']);
        $records = [$event->getAttributes(), $task->getAttributes()];

        foreach ([1, 2] as $_) {
            LinkedServiceCallCloser::reconcile($call->refresh());
            $this->assertSame($records, [$event->refresh()->getAttributes(), $task->refresh()->getAttributes()]);
        }
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $caller->id)->count());
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());

        $task->forceFill([
            'status' => TaskStatus::Ready,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();
        $claim = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'another-replacement-worker');
        $this->assertTrue($claim['claimed'], json_encode($claim, JSON_THROW_ON_ERROR));
        $this->assertSame($event->id, $task->refresh()->payload['workflow_history_event_id']);
        $this->assertSame(2, $task->attempt_count);
        $this->assertSame($records[0], $event->refresh()->getAttributes());
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());
    }

    public static function waitingAdmissionEvents(): iterable
    {
        yield 'handler started' => [HistoryEventType::ServiceCallStarted, [
            'wait_for' => 'completed',
        ]];
        yield 'durably scheduled' => [HistoryEventType::ServiceOperationScheduled, [
            'wait_for' => 'accepted',
            'workflow_resume_on_admission' => false,
        ]];
    }

    public function testAlreadyCompletedServiceResumesWhenAdmissionHistoryArrivesLate(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $service = $this->createRun();
        $caller = $this->createRun('caller');
        $call = $this->createCall($service, [
            'caller_workflow_instance_id' => $caller->workflow_instance_id,
            'caller_workflow_run_id' => $caller->id,
            'operation_mode' => 'sync',
            'metadata' => [
                'caller_terminal_history_event_id' => 'untrusted-caller-hint',
            ],
        ]);
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'early result'),
            'payload_codec' => 'avro',
        ]);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $caller->id)->count());
        $this->recordAdmission($caller, $call);
        $task = WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole();
        $this->assertTrue(
            $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'late-caller')['claimed']
        );
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $caller->id)
            ->where('event_type', HistoryEventType::ServiceCallCompleted->value)
            ->sole();
        $this->assertSame(
            'early result',
            Serializer::unserializeWithCodec('avro', $event->payload['response_payload']['blob'])
        );
    }

    public function testAsyncAdmissionKeepsItsRecordedResultWhenTheServiceCompletes(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        foreach ([[
            'operation_mode' => 'async',
        ], [
            'wait_for' => 'accepted',
        ]] as $options) {
            $service = $this->createRun();
            $caller = $this->createRun('caller');
            $call = $this->createCall($service, [
                'caller_workflow_instance_id' => $caller->workflow_instance_id,
                'caller_workflow_run_id' => $caller->id,
                'operation_mode' => $options['operation_mode'] ?? 'sync',
            ]);
            $event = $this->recordAdmission($caller, $call, $options);
            $saved = $event->refresh()
                ->getAttributes();
            WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', 'later result'),
                'payload_codec' => 'avro',
            ]);
            LinkedServiceCallCloser::reconcile($call->refresh());
            $this->assertSame('completed', $call->status);
            $this->assertSame($saved, $event->refresh()->getAttributes());
            $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $caller->id)->count());
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());
        }
    }

    public function testFailureAndCancellationResumeWithTheActualTerminalCause(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        foreach ([
            HistoryEventType::WorkflowFailed,
            HistoryEventType::WorkflowCancelled,
            HistoryEventType::WorkflowTerminated,
        ] as $terminal) {
            $service = $this->createRun();
            $caller = $this->createRun('caller');
            $call = $this->createCall($service, [
                'caller_workflow_instance_id' => $caller->workflow_instance_id,
                'caller_workflow_run_id' => $caller->id,
                'operation_mode' => 'sync',
            ]);
            $this->recordAdmission($caller, $call);
            WorkflowHistoryEvent::record($service, $terminal, [
                'message' => 'original service cause',
            ] + ($terminal === HistoryEventType::WorkflowFailed ? [
                'exception_type' => 'PaymentDeclined',
            ] : []));
            $task = WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole();
            $this->assertTrue(
                $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'failure-caller')['claimed']
            );
            $event = WorkflowHistoryEvent::query()->findOrFail($task->refresh()->payload['workflow_history_event_id']);
            $this->assertSame('original service cause', $event->payload['message']);
            $this->assertSame($terminal === HistoryEventType::WorkflowCancelled
                ? HistoryEventType::ServiceCallCancelled
                : HistoryEventType::ServiceCallFailed, $event->event_type);
            $this->assertSame($terminal === HistoryEventType::WorkflowCancelled
                ? 'service_call_cancelled'
                : ($terminal === HistoryEventType::WorkflowFailed ? 'PaymentDeclined' : 'handler_failed'), $event->payload['exception']['type']);
            $this->assertSame(7, $event->payload['sequence']);
        }
    }

    public function testEmbeddedCallerWaitsAndThenReplaysTheActualServiceResult(): void
    {
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $service = $this->createRun();
        $control = Mockery::mock(WorkflowControlPlane::class);
        $control->shouldReceive('start')
            ->once()
            ->andReturn([
                'started' => true,
                'workflow_instance_id' => $service->workflow_instance_id,
                'workflow_run_id' => $service->id,
            ]);
        $this->registerWorkflowService();
        $this->registerTrustedServiceOperationHost();
        $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane($control));
        $workflow = WorkflowStub::make(NexusWaitingCallerWorkflow::class, 'waiting-caller', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
        $this->assertNull($workflow->refresh()->output());
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
        $this->assertSame('accepted', $call->status);
        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ServiceOperationScheduled->value)
            ->sole();
        $this->assertFalse($scheduled->payload['workflow_resume_on_admission']);
        $this->app->call([new RunServiceCall($call->id), 'handle']);
        $this->assertSame('started', $call->refresh()->status);
        $this->assertSame($service->workflow_instance_id, $call->linked_workflow_instance_id);
        $this->assertSame($service->id, $call->linked_workflow_run_id);

        $expected = [
            'greeting' => 'hello world',
        ];
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', $expected),
            'payload_codec' => 'avro',
        ]);
        $this->assertSame('completed', $call->refresh()->status);
        $resume = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($resume->id), 'handle']);
        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertSame($expected, $workflow->refresh()->output());
        $this->assertSame('completed', $call->refresh()->status);
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ServiceCallCompleted->value)
            ->count());
    }

    public function testOutcomeWakeAndDeliveryRemainRecoverableAfterTransactionRollback(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $service = $this->createRun();
        $caller = $this->createRun('caller');
        $call = $this->createCall($service, [
            'caller_workflow_instance_id' => $caller->workflow_instance_id,
            'caller_workflow_run_id' => $caller->id,
            'operation_mode' => 'sync',
        ]);
        $this->recordAdmission($caller, $call);
        $connection = $call->getConnection();
        $connection->beginTransaction();
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'result'),
            'payload_codec' => 'avro',
        ]);
        $this->assertSame('completed', $call->refresh()->status);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $caller->id)->count());
        $connection->rollBack();
        $this->assertSame('started', $call->refresh()->status);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $caller->id)->count());

        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'result'),
            'payload_codec' => 'avro',
        ]);
        $task = WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole();
        $connection->beginTransaction();
        $this->assertTrue(
            $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'uncommitted-worker')['claimed']
        );
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());
        $connection->rollBack();
        $this->assertSame('completed', $call->refresh()->status);
        $this->assertSame(TaskStatus::Ready, $task->refresh()->status);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());
        $this->assertTrue(
            $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'committed-worker')['claimed']
        );
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)->count());
    }

    public function testSharedCallPreservesAsyncAdmissionAndResumesOnlyTheLaterWaitingBoundary(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $service = $this->createRun();
        $caller = $this->createRun('caller');
        $call = $this->createCall($service, [
            'caller_workflow_instance_id' => $caller->workflow_instance_id,
            'caller_workflow_run_id' => $caller->id,
            'operation_mode' => 'sync',
        ]);
        $admission = $this->recordAdmission($caller, $call, [
            'wait_for' => 'accepted',
        ]);
        $saved = $admission->refresh()
            ->getAttributes();
        $this->recordAdmission($caller, $call, [
            'sequence' => 8,
        ]);
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'result'),
            'payload_codec' => 'avro',
        ]);
        $task = WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole();
        $this->assertSame(8, $task->payload['workflow_sequence']);
        $this->assertTrue(
            $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'waiting-worker')['claimed']
        );
        $this->assertSame($saved, $admission->refresh()->getAttributes());
        $event = WorkflowHistoryEvent::query()->findOrFail($task->refresh()->payload['workflow_history_event_id']);
        $this->assertSame(8, $event->payload['sequence']);
    }

    public function testCompletionClosesOriginalCallAndRetainsSerializedResult(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run);
        $blob = Serializer::serializeWithCodec('avro', [
            'greeting' => 'hello world',
        ]);
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => $blob,
            'payload_codec' => 'avro',
        ]);

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(ServiceCallOutcome::Completed, $call->outcome);
        $this->assertTrue($event->recorded_at->equalTo($call->completed_at));
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $blob,
        ], $call->metadata['nexus_result']);
        $this->assertSame('caller', $call->caller_namespace);
        $this->assertSame($run->id, $call->linked_workflow_run_id);

        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();
        $view = (new DefaultServiceControlPlane())->describeCall($call->id, [
            'namespace' => 'service',
        ]);
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $blob,
        ], $view['result_envelope']);
        $this->assertSame('completed', $view['status']);
    }

    public function testLateBindingUsesOriginalTerminalTimestampAndNeverChangesTheWinner(): void
    {
        $run = $this->createRun();
        $first = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'first'),
            'payload_codec' => 'avro',
        ]);
        $call = $this->createCall($run);
        LinkedServiceCallCloser::reconcile($call);
        $saved = $call->getAttributes();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'exception_type' => 'LateFailure',
            'message' => 'a later observation must not replace completion',
        ]);
        LinkedServiceCallCloser::reconcile($call);

        $this->assertSame($saved, $call->refresh()->getAttributes());
        $this->assertSame($first->id, $call->metadata['linked_terminal_event_id']);
        $this->assertTrue($first->recorded_at->equalTo($call->completed_at));
        $this->assertSame(0, (new DefaultServiceControlPlane())->describeCall(
            $call->id,
            [
                'namespace' => 'service',
            ],
        )['retry_attempt_count']);
    }

    public function testServiceThatFinishesDuringAdmissionReturnsItsResultAndKeepsTheAttempt(): void
    {
        $run = $this->createRun();
        $blob = Serializer::serializeWithCodec('avro', 'finished during admission');
        $workflow = Mockery::mock(WorkflowControlPlane::class);
        $workflow->shouldReceive('start')
            ->once()
            ->andReturnUsing(static function () use ($run, $blob): array {
                WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
                    'output' => $blob,
                    'payload_codec' => 'avro',
                ]);

                return [
                    'started' => true,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                ];
            });
        $this->registerWorkflowService();
        $result = (new DefaultServiceControlPlane($workflow))->execute('php-service', 'greeter', 'greet', [
            'namespace' => 'service',
            'caller_namespace' => 'caller',
            'payload_codec' => 'avro',
            'payload_blob' => Serializer::serializeWithCodec('avro', ['world']),
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $blob,
        ], $result['result_envelope']);
        $this->assertSame($run->id, $result['linked_workflow_run_id']);
        $this->assertSame($run->id, $result['resolved_target_reference']);
        $this->assertSame(1, $result['retry_attempt_count']);
        $this->assertSame('completed', $result['service_call_attempts'][0]['status']);
    }

    public function testRolledBackTerminalHistoryCannotLeaveACompletedCall(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run);

        try {
            $run->getConnection()
                ->transaction(static function () use ($run): void {
                    WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
                        'output' => Serializer::serializeWithCodec('avro', 'uncommitted result'),
                        'payload_codec' => 'avro',
                    ]);
                    throw new RuntimeException('abort completion transaction');
                });
            $this->fail('The surrounding transaction must abort.');
        } catch (RuntimeException $exception) {
            $this->assertSame('abort completion transaction', $exception->getMessage());
        }

        $this->assertSame('started', $call->refresh()->status);
        $this->assertNull($call->completed_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, $run->refresh()->last_history_sequence);
    }

    public function testCancellationDuringAdmissionCannotBeReopenedByLateStartedDispatch(): void
    {
        $run = $this->createRun();
        $cancelledAt = null;
        $workflow = Mockery::mock(WorkflowControlPlane::class);
        $workflow->shouldReceive('start')
            ->once()
            ->andReturnUsing(static function () use ($run, &$cancelledAt): array {
                $call = WorkflowServiceCall::query()->sole();
                $call->forceFill([
                    'status' => 'cancelled',
                    'outcome' => ServiceCallOutcome::Cancelled->value,
                    'outcome_reason' => 'operator_cancelled',
                    'cancelled_at' => now(),
                ])->save();
                $cancelledAt = $call->refresh()
                    ->cancelled_at->toJSON();

                return [
                    'started' => true,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                ];
            });
        $this->registerWorkflowService();
        $result = (new DefaultServiceControlPlane($workflow))->execute('php-service', 'greeter', 'greet', [
            'namespace' => 'service',
            'caller_namespace' => 'caller',
            'payload_codec' => 'avro',
            'payload_blob' => Serializer::serializeWithCodec('avro', []),
        ]);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('cancelled', $result['outcome']);
        $this->assertSame('operator_cancelled', $result['outcome_reason']);
        $this->assertSame($cancelledAt, $result['cancelled_at']);
        $this->assertNull($result['started_at']);
        $this->assertSame($run->id, $result['linked_workflow_run_id']);
        $this->assertSame('cancelled', $result['service_call_attempts'][0]['status']);
    }

    public function testFailureCancellationAndTerminationCloseWithoutLosingTypedReason(): void
    {
        foreach ([
            [HistoryEventType::WorkflowFailed, 'failed', ServiceCallOutcome::HandlerFailed, 'failed_at'],
            [HistoryEventType::WorkflowCancelled, 'cancelled', ServiceCallOutcome::Cancelled, 'cancelled_at'],
            [HistoryEventType::WorkflowTerminated, 'failed', ServiceCallOutcome::HandlerFailed, 'failed_at'],
            [HistoryEventType::WorkflowTimedOut, 'failed', ServiceCallOutcome::HandlerFailed, 'failed_at'],
        ] as [$type, $status, $outcome, $timestamp]) {
            $run = $this->createRun();
            $call = $this->createCall($run);
            $event = WorkflowHistoryEvent::record($run, $type, [
                'exception_class' => 'ServiceFailure',
                'message' => 'service stopped',
            ]);

            $call->refresh();
            $this->assertSame($status, $call->status);
            $this->assertSame($outcome, $call->outcome);
            $this->assertTrue($event->recorded_at->equalTo($call->{$timestamp}));
            $view = (new DefaultServiceControlPlane())->describeCall($call->id, [
                'namespace' => 'service',
            ]);
            $this->assertSame('ServiceFailure', $view['error_type']);
            $this->assertSame('service stopped', $view['failure_message']);
            $this->assertArrayNotHasKey('result_envelope', $view);
        }
    }

    public function testLinkedWorkflowTimeoutBeforeAdmissionIsReconciledOnceWithItsTypedCause(): void
    {
        $run = $this->createRun();
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowTimedOut, [
            'exception_class' => \Workflow\V2\Exceptions\WorkflowTimeoutException::class,
            'message' => 'Workflow run timeout elapsed.',
            'timeout_kind' => 'run',
        ]);
        $call = $this->createCall($run);
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame('failed', $call->status);
        $this->assertSame(ServiceCallOutcome::HandlerFailed, $call->outcome);
        $this->assertTrue($event->recorded_at->equalTo($call->failed_at));
        $this->assertSame(
            \Workflow\V2\Exceptions\WorkflowTimeoutException::class,
            $call->outcome_metadata['service_error_type']
        );
        $this->assertSame('Workflow run timeout elapsed.', $call->failure_message);
        $before = $call->getRawOriginal();
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    #[DataProvider('cancellationTerminalWinners')]
    public function testLinkedCancellationKeepsItsReceiptAndTheTerminalWinner(HistoryEventType $terminal): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run, [
            'cancellation_policy' => [
                'propagate_to_linked_workflow' => true,
            ],
            'metadata' => [
                'trace' => 'keep-me',
            ],
        ]);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('cancel')
            ->once()
            ->andReturnUsing(static function () use ($run, $terminal): array {
                $payload = $terminal === HistoryEventType::WorkflowCompleted
                    ? [
                        'output' => Serializer::serializeWithCodec('avro', false),
                        'payload_codec' => 'avro',
                    ]
                    : [
                        'message' => 'target won',
                    ];
                WorkflowHistoryEvent::record($run, $terminal, $payload);
                return [
                    'accepted' => true,
                    'command_id' => 'linked-cancel',
                    'result' => 'private-result',
                ];
            });
        $control = new DefaultServiceControlPlane($workflows);
        $result = $control->cancelCall($call->id, [
            'namespace' => 'service',
        ]);
        $call->refresh();
        $this->assertSame($terminal === HistoryEventType::WorkflowCancelled, $result['accepted']);
        $this->assertSame(match ($terminal) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowCompleted => 'completed',
            default => 'failed',
        }, $call->status);
        $this->assertSame('keep-me', $call->metadata['trace']);
        $this->assertNotEmpty($call->metadata['linked_terminal_event_id']);
        $this->assertSame([
            'accepted' => true,
            'command_id' => 'linked-cancel',
        ], $call->metadata['linked_cancel']);
        if ($terminal === HistoryEventType::WorkflowCompleted) {
            $this->assertSame(Serializer::serializeWithCodec('avro', false), $call->metadata['nexus_result']['blob']);
        }
        $before = $call->getRawOriginal();
        $control->cancelCall($call->id, [
            'namespace' => 'service',
        ]);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    public static function cancellationTerminalWinners(): iterable
    {
        foreach ([
            HistoryEventType::WorkflowCompleted,
            HistoryEventType::WorkflowCancelled,
            HistoryEventType::WorkflowFailed,
        ] as $terminal) {
            yield $terminal->value => [$terminal];
        }
    }

    public function testLinkedCancellationFailureIsTypedWithoutLosingTheLocalCancellation(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run, [
            'cancellation_policy' => [
                'propagate_to_linked_workflow' => true,
            ],
        ]);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('cancel')
            ->once()
            ->andThrow(new RuntimeException('private backend detail'));
        $control = new DefaultServiceControlPlane($workflows);
        $result = $control->cancelCall($call->id, [
            'namespace' => 'service',
        ]);
        $this->assertTrue($result['accepted']);
        $call->refresh();
        $this->assertSame('cancelled', $call->status);
        $this->assertSame([
            'accepted' => false,
            'reason' => 'workflow_cancel_exception',
            'exception_type' => RuntimeException::class,
        ], $call->metadata['linked_cancel']);
        $before = $call->getRawOriginal();
        $control->cancelCall($call->id, [
            'namespace' => 'service',
        ]);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    public function testDeadlineStillWinsWhileLinkedCancellationIsInFlight(): void
    {
        Carbon::setTestNow('2026-10-11T10:00:00.123456Z');
        try {
            $run = $this->createRun();
            $deadline = now()
                ->addSecond();
            $call = $this->createCall($run, [
                'cancellation_policy' => [
                    'propagate_to_linked_workflow' => true,
                ],
                'call_deadline_at' => $deadline,
            ]);
            $workflows = Mockery::mock(WorkflowControlPlane::class);
            $workflows->shouldReceive('cancel')
                ->once()
                ->andReturnUsing(static function () use ($run, $deadline): array {
                    Carbon::setTestNow($deadline);
                    WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCancelled, [
                        'message' => 'late cancellation',
                    ]);
                    return [
                        'accepted' => true,
                    ];
                });
            $result = (new DefaultServiceControlPlane($workflows))->cancelCall($call->id, [
                'namespace' => 'service',
            ]);
            $this->assertFalse($result['accepted']);
            $call->refresh();
            $this->assertSame(ServiceCallOutcome::TimedOut, $call->outcome);
            $this->assertSame([
                'accepted' => true,
            ], $call->metadata['linked_cancel']);
            $this->assertTrue($deadline->equalTo($call->failed_at));
            $this->assertNull($call->cancelled_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testUnrelatedNamespaceRunInstanceAndBindingRemainOpen(): void
    {
        $run = $this->createRun();
        $another = $this->createRun();
        $calls = [
            $this->createCall($run, [
                'target_namespace' => 'another-namespace',
            ]),
            $this->createCall($run, [
                'linked_workflow_run_id' => $another->id,
            ]),
            $this->createCall($run, [
                'linked_workflow_instance_id' => $another->workflow_instance_id,
            ]),
            $this->createCall($run, [
                'resolved_binding_kind' => ServiceCallBindingKind::WorkflowUpdate->value,
            ]),
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'done'),
            'payload_codec' => 'avro',
        ]);

        foreach ($calls as $call) {
            LinkedServiceCallCloser::reconcile($call);
            $this->assertSame('started', $call->refresh()->status);
            $this->assertNull($call->completed_at);
        }
    }

    public function testExternalResultKeepsIntegrityMetadataWithoutCopyingProviderUriIntoMetadata(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run);
        $blob = Serializer::serializeWithCodec('avro', str_repeat('hello', 100));
        $reference = [
            'uri' => 'memory://service/result',
            'size_bytes' => strlen($blob),
            'sha256' => hash('sha256', $blob),
            'codec' => 'avro',
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => [
                'codec' => 'avro',
                'external_storage' => $reference,
            ],
            'payload_codec' => 'avro',
        ]);

        $call->refresh();
        $this->assertSame($reference['uri'], $call->output_payload_reference);
        $this->assertArrayNotHasKey('uri', $call->metadata['nexus_result']['external_reference']);
        $this->assertSame($reference['sha256'], $call->metadata['nexus_result']['external_reference']['sha256']);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();
        $view = (new DefaultServiceControlPlane())->describeCall($call->id, [
            'namespace' => 'service',
        ]);
        $this->assertSameJsonObject([
            'codec' => 'avro',
            'external_storage' => $reference,
        ], $view['result_envelope']);
    }

    #[DataProvider('externalCallerNamespaces')]
    public function testExternalTerminalDeliveryBelongsToTheCallerNamespace(string $namespace): void
    {
        ExternalPayloadStorage::flushVerifiedCache();
        try {
            [$caller, $call, $task, $targetStorage, $callerStorage, $blob] = $this->externalCaller($namespace);
            $originalCall = $call->getAttributes();
            $claim = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'cold-caller');
            $this->assertTrue($claim['claimed']);
            $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallCompleted->value)->sole();
            $envelope = $event->payload['response_payload'];
            $this->assertStringStartsWith('memory://' . $namespace . '/', $envelope['external_storage']['uri']);
            $this->assertSame($call->id, $event->payload['service_call_id']);
            $this->assertSame(7, $event->payload['sequence']);
            $this->assertSame($task->id, $event->workflow_task_id);
            $this->assertSame($envelope, $event->payload['service_call']['result_envelope']);
            $this->assertSame($envelope, $event->payload['response_or_failure_surface']['result_envelope']);
            $this->assertSame($originalCall, $call->refresh()->getAttributes());
            $this->assertSame(strlen($blob), $envelope['external_storage']['size_bytes']);
            $this->assertSame(hash('sha256', $blob), $envelope['external_storage']['sha256']);
            if ($namespace === 'service') {
                $this->assertSame($call->output_payload_reference, $envelope['external_storage']['uri']);
                $this->assertSame(1, $targetStorage->writes);
            } else {
                $this->assertNotSame($call->output_payload_reference, $envelope['external_storage']['uri']);
                $this->assertSame(1, $callerStorage->writes);
                $targetStorage->failReads = true;
            }
            ExternalPayloadStorage::flushVerifiedCache();
            $this->assertSame($blob, ExternalPayloads::payloadBlob($envelope, 'avro', $namespace));
            $originalEvent = $event->getAttributes();
            ServiceCallWorkflowDelivery::recordForClaim($task->fresh(), $caller->fresh());
            $this->assertSame($originalEvent, $event->refresh()->getAttributes());
            $this->assertSame(1, $callerStorage->writes);
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallCompleted->value)->count());
        } finally {
            ExternalPayloadStorage::flushVerifiedCache();
        }
    }

    public static function externalCallerNamespaces(): iterable
    {
        yield 'cross namespace' => ['caller'];
        yield 'same namespace' => ['service'];
    }

    public function testCallerStorageFailureRollsBackTheOriginalClaimAndCanRecover(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();
        try {
            [$caller, $call, $task, , $callerStorage] = $this->externalCaller('caller');
            $originalCall = $call->getAttributes();
            $callerStorage->failWrites = true;
            $failure = null;
            try {
                $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'refused-caller');
            } catch (RuntimeException $error) {
                $failure = $error;
            }
            $this->assertInstanceOf(
                RuntimeException::class,
                $failure,
                'Caller storage must be available before publishing its result history.'
            );
            $this->assertSame('caller write refused', $failure->getMessage());
            $this->assertSame(TaskStatus::Ready, $task->refresh()->status);
            $this->assertSame(0, $task->attempt_count);
            $this->assertNull($task->lease_owner);
            $this->assertTrue($task->payload['service_call_terminal_pending']);
            $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallCompleted->value)->count());
            $this->assertSame($originalCall, $call->refresh()->getAttributes());
            $callerStorage->failWrites = false;
            $this->assertTrue(
                $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'restored-caller')['claimed']
            );
            $this->assertSame(1, $task->refresh()->attempt_count);
            $this->assertSame($task->id, WorkflowHistoryEvent::query()->where('workflow_run_id', $caller->id)
                ->where('event_type', HistoryEventType::ServiceCallCompleted->value)->sole()->workflow_task_id);
        } finally {
            ExternalPayloadStorage::flushVerifiedCache();
        }
    }

    /**
     * @return array{WorkflowRun, WorkflowServiceCall, WorkflowTask, NexusNamespaceExternalStorage, NexusNamespaceExternalStorage, string}
     */
    private function externalCaller(string $namespace): array
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $targetStorage = new NexusNamespaceExternalStorage('service');
        $callerStorage = $namespace === 'service' ? $targetStorage : new NexusNamespaceExternalStorage('caller');
        $drivers = [
            'service' => $targetStorage,
            $namespace => $callerStorage,
        ];
        $policy = Mockery::mock(ExternalPayloadStoragePolicy::class);
        $policy->shouldReceive('driverFor')
            ->andReturnUsing(static fn ($name) => $drivers[$name] ?? null);
        $policy->shouldReceive('thresholdBytesFor')
            ->andReturn(32);
        $this->app->instance(ExternalPayloadStoragePolicy::class, $policy);
        $service = $this->createRun();
        $caller = $this->createRun($namespace);
        $call = $this->createCall($service, [
            'caller_namespace' => $namespace,
            'caller_workflow_instance_id' => $caller->workflow_instance_id,
            'caller_workflow_run_id' => $caller->id,
            'operation_mode' => 'sync',
        ]);
        $this->recordAdmission($caller, $call);
        $blob = Serializer::serializeWithCodec('avro', [
            'payload' => str_repeat('value', 50),
            'bigint' => PHP_INT_MAX,
            'nullable' => null,
            'unicode' => '雪☃',
        ]);
        $stored = ExternalPayloads::externalizeForNamespace($blob, 'avro', 'service');
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => ExternalPayloads::historyValue($stored, 'avro', 'service'),
            'payload_codec' => 'avro',
        ]);

        return [$caller, $call->refresh(), WorkflowTask::query()->where('workflow_run_id', $caller->id)->sole(),
            $targetStorage, $callerStorage, $blob];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function recordAdmission(
        WorkflowRun $caller,
        WorkflowServiceCall $call,
        array $options = []
    ): WorkflowHistoryEvent {
        return WorkflowHistoryEvent::record($caller, HistoryEventType::ServiceCallStarted, array_replace([
            'sequence' => 7,
            'service_call_id' => $call->id,
            'operation_mode' => 'sync',
            'wait_for' => 'completed',
            'payload_codec' => 'avro',
            'service_call' => (new DefaultServiceControlPlane())->describeCall($call->id, [
                'namespace' => 'service',
            ]),
        ], $options));
    }

    private function registerWorkflowService(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'service',
            'endpoint_name' => 'php-service',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'greeter',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'greet',
            'operation_mode' => 'async',
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'nexus.service',
        ]);
    }

    private function registerTrustedServiceOperationHost(): void
    {
        $this->app->instance(
            WorkflowServiceOperationAuthorizer::class,
            new class() implements WorkflowServiceOperationAuthorizer {
                public function authorize(WorkflowRun $run, array $command): ServiceCallPrincipal
                {
                    if ($run->namespace !== 'caller' || ($command['namespace'] ?? null) !== 'service'
                        || [$command['endpoint_name'], $command['service_name'], $command['operation_name']]
                            !== ['php-service', 'greeter', 'greet']) {
                        throw new ServiceOperationAuthorizationException();
                    }

                    return new ServiceCallPrincipal(subject: 'workflow:' . $run->id, method: 'workflow');
                }
            }
        );
    }

    private function createRun(string $namespace = 'service'): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'namespace' => $namespace,
            'workflow_type' => 'nexus.service',
            'workflow_class' => 'nexus.service',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_type' => 'nexus.service',
            'workflow_class' => 'nexus.service',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCall(WorkflowRun $run, array $overrides = []): WorkflowServiceCall
    {
        return WorkflowServiceCall::query()->create(array_merge([
            'namespace' => 'service',
            'endpoint_name' => 'php-service',
            'service_name' => 'greeter',
            'operation_name' => 'greet',
            'caller_namespace' => 'caller',
            'target_namespace' => 'service',
            'linked_workflow_instance_id' => $run->workflow_instance_id,
            'linked_workflow_run_id' => $run->id,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'status' => 'started',
            'outcome' => ServiceCallOutcome::Accepted->value,
            'operation_mode' => 'async',
            'payload_codec' => 'avro',
            'accepted_at' => now(),
            'started_at' => now(),
        ], $overrides));
    }
}

final class NexusNamespaceExternalStorage implements ExternalPayloadStorageDriver
{
    public bool $failWrites = false;

    public bool $failReads = false;

    public int $writes = 0;

    /**
     * @var array<string, string>
     */
    private array $objects = [];

    public function __construct(
        private readonly string $namespace
    ) {
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        if ($this->failWrites) {
            throw new RuntimeException('caller write refused');
        }
        ++$this->writes;
        $uri = 'memory://' . $this->namespace . '/' . $sha256;
        $this->objects[$uri] = $data;

        return $uri;
    }

    public function get(string $uri): string
    {
        if ($this->failReads || ! isset($this->objects[$uri])) {
            throw new RuntimeException('Reference is unavailable in this namespace.');
        }

        return $this->objects[$uri];
    }

    public function delete(string $uri): void
    {
        unset($this->objects[$uri]);
    }
}

#[Type('nexus-waiting-caller')]
final class NexusWaitingCallerWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $result = Workflow::serviceOperation('php-service', 'greeter', 'greet', ['world'], new ServiceOperationOptions(
            targetNamespace: 'service',
            modeOverride: 'sync',
            waitFor: 'completed',
        ));

        return $result instanceof ServiceOperationResult ? $result->responsePayload : null;
    }
}
