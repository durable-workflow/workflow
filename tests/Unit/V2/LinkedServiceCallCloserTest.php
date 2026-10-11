<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
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
use Workflow\V2\Support\LinkedServiceCallCloser;
use Workflow\V2\Support\ServiceCallDeadline;
use Workflow\V2\Support\ServiceCallView;
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

    public function testTerminalServiceOutcomeResumesItsOriginalWaitingCallerOnce(): void
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
        WorkflowHistoryEvent::record($caller, HistoryEventType::ServiceCallStarted, [
            'sequence' => 7,
            'service_call_id' => $call->id,
            'operation_mode' => 'sync',
            'wait_for' => 'completed',
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,
            'payload_codec' => 'avro',
            'caller_sdk_language' => 'sdk-python',
            'service_call' => (new DefaultServiceControlPlane())->describeCall($call->id, [
                'namespace' => 'service',
            ]),
        ]);
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
        $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane($control));
        $workflow = WorkflowStub::make(NexusWaitingCallerWorkflow::class, 'waiting-caller', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
        $this->assertNull($workflow->refresh()->output());
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
        $this->assertSame('started', $call->status);

        $expected = [
            'greeting' => 'hello world',
        ];
        WorkflowHistoryEvent::record($service, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', $expected),
            'payload_codec' => 'avro',
        ]);
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
