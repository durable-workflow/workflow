<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunUpdateView;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Support\WorkflowTaskPayload;
use Workflow\V2\Worker\WorkflowFiberRunner;
use Workflow\V2\Workflow as V2Workflow;
use Workflow\V2\WorkflowStub;

final class V2WorkflowTaskBridgeTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    private ?string $storageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

    public function testBridgeIsResolvableFromContainer(): void
    {
        $bridge = $this->app->make(WorkflowTaskBridge::class);

        $this->assertInstanceOf(DefaultWorkflowTaskBridge::class, $bridge);
    }

    public function testPollReturnsReadyWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(1, $results);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame('redis', $results[0]['connection']);
        $this->assertSame('default', $results[0]['queue']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
    }

    public function testPollExcludesNonWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollExcludesFutureAvailableTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinutes(5),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersbyQueue(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll('redis', 'other-queue');

        $this->assertCount(0, $results);
    }

    public function testPollWithNullFiltersReturnsAllReadyTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null);

        $this->assertCount(1, $results);
    }

    public function testClaimStatusClaimsReadyTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);

        $task->refresh();
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame('server-worker-1', $task->lease_owner);
    }

    public function testClaimStatusUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                $this->calls[] = ['recordActivityStarted', $run->id, $execution->id, $attempt->id, $task->id];

                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testClaimStatusRejectsNonExistentTask(): void
    {
        $result = $this->bridge->claimStatus('nonexistent-task-id');

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    public function testClaimStatusRejectsAlreadyLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_claimable', $result['reason']);
    }

    public function testClaimStatusRejectsTaskOnTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('run_closed', $result['reason']);
    }

    public function testClaimReturnsNullOnFailure(): void
    {
        $result = $this->bridge->claim('nonexistent-task-id');

        $this->assertNull($result);
    }

    public function testClaimReturnsPayloadOnSuccess(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->claim($task->id, 'worker-1');

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertArrayNotHasKey('reason', $result);
    }

    public function testHistoryPayloadReturnsRunHistory(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, \Workflow\V2\Enums\HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        $result = $this->bridge->historyPayload($task->id);

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $result['workflow_type']);
        $this->assertCount(1, $result['history_events']);
        $this->assertSame('WorkflowStarted', $result['history_events'][0]['event_type']);
    }

    public function testFiberRunnerReplaysHistoryPayloadWithBridgeAssignedCommandSequences(): void
    {
        $run = $this->createWaitingRun();
        $workflowClass = BridgeHistorySequenceWorkflow::class;
        $workflowType = 'bridge-history-sequence-workflow';

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);
        $instance->forceFill([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ])->save();
        $run->forceFill([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', ['polyglot']),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ]);

        $firstTask = $this->createLeasedTask($run);
        $firstHistory = $this->bridge->historyPayload($firstTask->id);

        $this->assertNotNull($firstHistory);
        $this->assertSame(2, $firstHistory['last_history_sequence']);

        $firstStep = WorkflowFiberRunner::forClass(
            $workflowClass,
            $instance->id,
            $run->id,
            ['polyglot'],
            'avro',
            $firstHistory['history_events'],
        )->step();

        $this->assertSame('schedule_activity', $firstStep->command['type']);
        $this->assertSame('demo.first', $firstStep->command['activity_type']);

        $scheduled = $this->bridge->complete($firstTask->id, $firstStep->commands);

        $this->assertTrue($scheduled['completed']);
        $this->assertCount(1, $scheduled['created_task_ids']);

        /** @var WorkflowHistoryEvent $scheduledEvent */
        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();

        $this->assertSame(3, (int) $scheduledEvent->sequence);
        $this->assertSame(3, $scheduledEvent->payload['sequence']);

        /** @var ActivityTaskBridge $activityBridge */
        $activityBridge = $this->app->make(ActivityTaskBridge::class);
        $activityClaim = $activityBridge->claim($scheduled['created_task_ids'][0], 'activity-worker-1');

        $this->assertNotNull($activityClaim);
        $this->assertIsString($activityClaim['activity_attempt_id']);

        $activityAttemptId = $activityClaim['activity_attempt_id'];
        $activityResult = $activityBridge->complete($activityAttemptId, 'first-result');

        $this->assertTrue($activityResult['recorded']);
        $this->assertIsString($activityResult['next_task_id']);

        $resumeTaskId = $activityResult['next_task_id'];
        $resumeClaim = $this->bridge->claim($resumeTaskId, 'external-worker-1');

        $this->assertNotNull($resumeClaim);

        $resumeHistory = $this->bridge->historyPayload($resumeTaskId);

        $this->assertNotNull($resumeHistory);

        $completedEvent = collect($resumeHistory['history_events'])
            ->firstWhere('event_type', 'ActivityCompleted');

        $this->assertIsArray($completedEvent);
        $this->assertSame(3, $completedEvent['payload']['sequence']);

        $resumedStep = WorkflowFiberRunner::forClass(
            $workflowClass,
            $instance->id,
            $run->id,
            ['polyglot'],
            'avro',
            $resumeHistory['history_events'],
        )->step();

        $this->assertSame('schedule_activity', $resumedStep->command['type']);
        $this->assertSame('demo.second', $resumedStep->command['activity_type']);
        $this->assertSame(
            ['first-result'],
            Serializer::unserializeWithCodec('avro', $resumedStep->command['arguments']),
        );
    }

    public function testFiberRunnerReplaysExternalPayloadHistoryWithBridgeNamespace(): void
    {
        $namespace = 'tenant-a';
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $policy = $this->bindNamespacedExternalPayloadPolicy($driver, $namespace);
        $run = $this->createWaitingRun($namespace);
        $workflowClass = BridgeHistorySequenceWorkflow::class;
        $workflowType = 'bridge-history-sequence-workflow';

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);
        $instance->forceFill([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ])->save();
        $run->forceFill([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', ['polyglot']),
        ])->save();

        $storedResult = ExternalPayloads::externalize(
            Serializer::serializeWithCodec('avro', 'first-result'),
            'avro',
            $driver,
            1,
        );

        WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
            'sequence' => 1,
            'result' => ExternalPayloads::historyValue($storedResult, 'avro', $namespace),
            'payload_codec' => 'avro',
        ]);

        $task = $this->createLeasedTask($run);
        $history = $this->bridge->historyPayload($task->id);

        $this->assertNotNull($history);
        $this->assertSame($namespace, $history['namespace']);
        $this->assertSame($namespace, $history['history_events'][0]['namespace']);

        $step = WorkflowFiberRunner::forClass(
            $workflowClass,
            $instance->id,
            $run->id,
            ['polyglot'],
            'avro',
            $history['history_events'],
        )->step();

        $this->assertSame('schedule_activity', $step->command['type']);
        $this->assertSame('demo.second', $step->command['activity_type']);
        $this->assertSame(
            ['first-result'],
            Serializer::unserializeWithCodec('avro', $step->command['arguments']),
        );
        $this->assertSame([$namespace], $policy->driverNamespaces);
    }

    public function testFiberRunnerReplaysRealWorkflowStubSignalReceivedPayloadFromBridgeHistory(): void
    {
        Queue::fake();
        BridgeProtocolSignalReplayWorkflow::reset();

        $workflow = WorkflowStub::make(BridgeProtocolSignalReplayWorkflow::class, 'bridge-protocol-signal-replay');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertIsString($runId);

        /** @var WorkflowTask $firstTask */
        $firstTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertNotNull($this->bridge->claim($firstTask->id, 'protocol-worker-1'));

        $firstHistory = $this->bridge->historyPayload($firstTask->id);

        $this->assertNotNull($firstHistory);

        $firstStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $firstHistory['payload_codec'],
            $firstHistory['history_events'],
        )->step();

        $this->assertFalse($firstStep->completed);
        $this->assertSame('open_signal_wait', $firstStep->command['type']);
        $this->assertSame('increment', $firstStep->command['signal_name']);

        $opened = $this->bridge->complete($firstTask->id, $firstStep->commands);

        $this->assertTrue($opened['completed']);
        $this->assertSame('waiting', $opened['run_status']);

        $signalResult = $workflow->signal('increment', 7);

        $this->assertTrue($signalResult->accepted());

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $signalResult->commandId())
            ->sole();

        /** @var WorkflowHistoryEvent $storedSignalReceived */
        $storedSignalReceived = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->sole();

        $this->assertSame($signal->id, $storedSignalReceived->payload['signal_id'] ?? null);
        $this->assertArrayNotHasKey('arguments', $storedSignalReceived->payload);
        $this->assertArrayNotHasKey('workflow_sequence', $storedSignalReceived->payload);

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame($signal->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertNotNull($this->bridge->claim($signalTask->id, 'protocol-worker-2'));

        $resumeHistory = $this->bridge->historyPayload($signalTask->id);
        $pagedHistory = $this->bridge->historyPayloadPaginated($signalTask->id);

        $this->assertNotNull($resumeHistory);
        $this->assertNotNull($pagedHistory);

        foreach ([$resumeHistory, $pagedHistory] as $history) {
            $received = collect($history['history_events'])
                ->firstWhere('event_type', HistoryEventType::SignalReceived->value);

            $this->assertIsArray($received);
            $this->assertSame($signal->id, $received['payload']['signal_id'] ?? null);
            $this->assertSame($signal->payload_codec, $received['payload']['payload_codec'] ?? null);
            $this->assertSame(
                [7],
                Serializer::unserializeWithCodec(
                    $received['payload']['arguments']['codec'],
                    $received['payload']['arguments']['blob'],
                ),
            );
        }

        $resumeStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $resumeHistory['payload_codec'],
            $resumeHistory['history_events'],
        )->step();

        $this->assertFalse($resumeStep->completed);
        $this->assertSame('open_signal_wait', $resumeStep->command['type']);
        $this->assertSame('increment', $resumeStep->command['signal_name']);
        $this->assertSame(7, BridgeProtocolSignalReplayWorkflow::lastCount());
    }

    public function testBridgeHistoryExposesRapidAcceptedSignalsInInputOrder(): void
    {
        $run = $this->createWaitingRun();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);
        $staleRun = $run->fresh();
        $codec = CodecRegistry::defaultCodec();

        foreach (range(1, 10) as $amount) {
            $command = WorkflowCommand::query()->create([
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'resolved_workflow_run_id' => $run->id,
                'command_type' => 'signal',
                'target_scope' => 'instance',
                'status' => 'accepted',
                'outcome' => 'signal_received',
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'payload_codec' => $codec,
                'payload' => Serializer::serializeWithCodec($codec, [
                    'name' => 'increment',
                    'arguments' => [$amount],
                ]),
                'command_sequence' => $amount,
                'message_sequence' => $amount,
                'accepted_at' => now()
                    ->addMicroseconds($amount),
            ]);
            $signal = WorkflowSignal::query()->create([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'target_scope' => 'instance',
                'resolved_workflow_run_id' => $run->id,
                'signal_name' => 'increment',
                'signal_wait_id' => sprintf('signal:%02d', $amount),
                'status' => 'received',
                'outcome' => 'signal_received',
                'command_sequence' => $amount,
                'payload_codec' => $codec,
                'arguments' => Serializer::serializeWithCodec($codec, [$amount]),
                'received_at' => $command->accepted_at,
            ]);

            $staleRun->forceFill([
                'last_history_sequence' => 0,
            ]);
            $staleRun->syncOriginalAttributes(['last_history_sequence']);

            WorkflowHistoryEvent::record($staleRun, HistoryEventType::SignalReceived, [
                'workflow_command_id' => $command->id,
                'signal_id' => $signal->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'signal_name' => 'increment',
                'signal_wait_id' => $signal->signal_wait_id,
            ], null, $command);
        }

        $history = $this->bridge->historyPayload($task->id);
        $this->assertNotNull($history);

        $signalEvents = collect($history['history_events'])
            ->filter(static fn (array $event): bool => $event['event_type'] === HistoryEventType::SignalReceived->value)
            ->values();
        $observedAmounts = $signalEvents
            ->map(static function (array $event): int {
                $arguments = Serializer::unserializeWithCodec(
                    $event['payload']['arguments']['codec'],
                    $event['payload']['arguments']['blob'],
                );

                return (int) $arguments[0];
            })
            ->all();

        $this->assertSame(range(1, 10), $signalEvents->pluck('sequence')->all());
        $this->assertSame(range(1, 10), $observedAmounts);
        $this->assertSame(55, array_sum($observedAmounts));
    }

    public function testProtocolOpenSignalWaitUsesBufferedSignalWaitIdWhenSignalArrivesDuringLeasedTask(): void
    {
        Queue::fake();
        BridgeProtocolSignalReplayWorkflow::reset();

        $workflow = WorkflowStub::make(BridgeProtocolSignalReplayWorkflow::class, 'bridge-protocol-buffered-signal');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertIsString($runId);

        /** @var WorkflowTask $firstTask */
        $firstTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertNotNull($this->bridge->claim($firstTask->id, 'protocol-worker-1'));

        $firstHistory = $this->bridge->historyPayload($firstTask->id);

        $this->assertNotNull($firstHistory);

        $firstStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $firstHistory['payload_codec'],
            $firstHistory['history_events'],
        )->step();

        $this->assertFalse($firstStep->completed);
        $this->assertSame('open_signal_wait', $firstStep->command['type']);
        $this->assertSame('increment', $firstStep->command['signal_name']);

        $signalResult = $workflow->signal('increment', 7);

        $this->assertTrue($signalResult->accepted());

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $signalResult->commandId())
            ->sole();
        $bufferedWaitId = $signal->signal_wait_id;

        $this->assertIsString($bufferedWaitId);

        /** @var WorkflowHistoryEvent $received */
        $received = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->sole();

        $this->assertSame($bufferedWaitId, $received->payload['signal_wait_id'] ?? null);

        $opened = $this->bridge->complete($firstTask->id, $firstStep->commands);

        $this->assertTrue($opened['completed']);
        $this->assertSame('waiting', $opened['run_status']);
        $this->assertCount(1, $opened['created_task_ids']);

        /** @var WorkflowHistoryEvent $waitOpened */
        $waitOpened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->sole();

        $this->assertSame('increment', $waitOpened->payload['signal_name'] ?? null);
        $this->assertSame($bufferedWaitId, $waitOpened->payload['signal_wait_id'] ?? null);

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->whereKey($opened['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame($signal->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame($bufferedWaitId, $signalTask->payload['signal_wait_id'] ?? null);

        $this->assertNotNull($this->bridge->claim($signalTask->id, 'protocol-worker-2'));

        $resumeHistory = $this->bridge->historyPayload($signalTask->id);

        $this->assertNotNull($resumeHistory);

        $resumeStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $resumeHistory['payload_codec'],
            $resumeHistory['history_events'],
        )->step();

        $this->assertFalse($resumeStep->completed);
        $this->assertSame('open_signal_wait', $resumeStep->command['type']);
        $this->assertSame(7, BridgeProtocolSignalReplayWorkflow::lastCount());
    }

    public function testProtocolSignalAfterOpenSignalWaitUsesCommittedWaitId(): void
    {
        Queue::fake();
        BridgeProtocolSignalReplayWorkflow::reset();

        $workflow = WorkflowStub::make(BridgeProtocolSignalReplayWorkflow::class, 'bridge-protocol-open-signal');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertIsString($runId);

        /** @var WorkflowTask $firstTask */
        $firstTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertNotNull($this->bridge->claim($firstTask->id, 'protocol-worker-1'));

        $firstHistory = $this->bridge->historyPayload($firstTask->id);

        $this->assertNotNull($firstHistory);

        $firstStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $firstHistory['payload_codec'],
            $firstHistory['history_events'],
        )->step();

        $opened = $this->bridge->complete($firstTask->id, $firstStep->commands);

        $this->assertTrue($opened['completed']);
        $this->assertSame('waiting', $opened['run_status']);

        /** @var WorkflowHistoryEvent $waitOpened */
        $waitOpened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->sole();
        $openWaitId = $waitOpened->payload['signal_wait_id'] ?? null;

        $this->assertIsString($openWaitId);

        $signalResult = $workflow->signal('increment', 3);

        $this->assertTrue($signalResult->accepted());

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $signalResult->commandId())
            ->sole();

        $this->assertSame($openWaitId, $signal->signal_wait_id);

        /** @var WorkflowHistoryEvent $received */
        $received = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->sole();

        $this->assertSame($openWaitId, $received->payload['signal_wait_id'] ?? null);

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame($signal->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame($openWaitId, $signalTask->payload['signal_wait_id'] ?? null);

        $this->assertNotNull($this->bridge->claim($signalTask->id, 'protocol-worker-2'));

        $resumeHistory = $this->bridge->historyPayload($signalTask->id);

        $this->assertNotNull($resumeHistory);

        $resumeStep = WorkflowFiberRunner::forClass(
            BridgeProtocolSignalReplayWorkflow::class,
            $workflow->id(),
            $runId,
            [],
            $resumeHistory['payload_codec'],
            $resumeHistory['history_events'],
        )->step();

        $this->assertFalse($resumeStep->completed);
        $this->assertSame('open_signal_wait', $resumeStep->command['type']);
        $this->assertSame(3, BridgeProtocolSignalReplayWorkflow::lastCount());
    }

    public function testHistoryPayloadReturnsLegacyArgumentsAndExternalizedEnvelope(): void
    {
        $run = $this->createWaitingRun();
        $codec = CodecRegistry::defaultCodec();
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $storedArguments = ExternalPayloads::externalize(
            Serializer::serializeWithCodec($codec, ['Taylor']),
            $codec,
            $driver,
            1,
        );
        $run->forceFill([
            'payload_codec' => $codec,
            'arguments' => $storedArguments,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $full = $this->bridge->historyPayload($task->id);
        $page = $this->bridge->historyPayloadPaginated($task->id);

        foreach ([$full, $page] as $payload) {
            $this->assertIsArray($payload);
            $this->assertSame($storedArguments, $payload['arguments']);
            $this->assertIsArray($payload['arguments_envelope']);
            $this->assertSame($codec, $payload['arguments_envelope']['codec']);
            $this->assertArrayHasKey('external_storage', $payload['arguments_envelope']);
            $this->assertArrayNotHasKey('blob', $payload['arguments_envelope']);
            $this->assertSame(ExternalPayloadReference::SCHEMA, $payload['arguments_envelope']['external_storage']['schema']);
        }
    }

    public function testHistoryPayloadReturnsNullForMissingTask(): void
    {
        $result = $this->bridge->historyPayload('nonexistent');

        $this->assertNull($result);
    }

    public function testHistoryPayloadPublishesAuthoritativeRunBudget(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 1);

        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        $expected = HistoryBudget::forRun($run);

        $payloads = [
            $this->bridge->historyPayload($task->id),
            $this->bridge->historyPayloadPaginated($task->id),
        ];

        foreach ($payloads as $payload) {
            $this->assertIsArray($payload);
            $this->assertSame($expected['history_event_count'], $payload['total_history_events']);
            $this->assertSame($expected['history_size_bytes'], $payload['history_size_bytes']);
            $this->assertTrue($payload['continue_as_new_recommended']);
            $this->assertSame(
                HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED,
                $payload['history_budget_pressure'],
            );
        }
    }

    public function testHistoryPayloadPaginatedReturnsFirstPage(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
                'sequence' => $i,
                'result' => "value-{$i}",
            ], $task);
        }

        $result = $this->bridge->historyPayloadPaginated($task->id, 0, 3);

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame(0, $result['after_sequence']);
        $this->assertSame(3, $result['page_size']);
        $this->assertTrue($result['has_more']);
        $this->assertNotNull($result['next_after_sequence']);
        $this->assertCount(3, $result['history_events']);
        $this->assertSame(1, $result['history_events'][0]['sequence']);
        $this->assertSame(3, $result['history_events'][2]['sequence']);
    }

    public function testHistoryPayloadPaginatedReturnsLastPage(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
                'sequence' => $i,
                'result' => "value-{$i}",
            ], $task);
        }

        $result = $this->bridge->historyPayloadPaginated($task->id, 3, 3);

        $this->assertNotNull($result);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_after_sequence']);
        $this->assertCount(2, $result['history_events']);
        $this->assertSame(4, $result['history_events'][0]['sequence']);
        $this->assertSame(5, $result['history_events'][1]['sequence']);
    }

    public function testHistoryPayloadPaginatedReturnsEmptyPageBeyondEnd(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        $result = $this->bridge->historyPayloadPaginated($task->id, 999, 10);

        $this->assertNotNull($result);
        $this->assertFalse($result['has_more']);
        $this->assertNull($result['next_after_sequence']);
        $this->assertCount(0, $result['history_events']);
    }

    public function testHistoryPayloadPaginatedReturnsNullForMissingTask(): void
    {
        $result = $this->bridge->historyPayloadPaginated('nonexistent');

        $this->assertNull($result);
    }

    public function testHistoryPayloadPaginatedClampsPageSize(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ], $task);

        // Request page_size of 5000, should be clamped to MAX_HISTORY_PAGE_SIZE
        $result = $this->bridge->historyPayloadPaginated($task->id, 0, 5000);

        $this->assertNotNull($result);
        $this->assertSame(1000, $result['page_size']);
    }

    public function testFailRecordsTaskFailure(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, 'Worker crashed');

        $this->assertTrue($result['recorded']);
        $this->assertNull($result['reason']);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Worker crashed', $task->last_error);
        $this->assertNull($task->lease_expires_at);
    }

    public function testFailWithThrowable(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, new RuntimeException('Replay failed'));

        $this->assertTrue($result['recorded']);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Replay failed', $task->last_error);
    }

    public function testFailRejectsCompletedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->fail($task->id, 'Late failure');

        $this->assertFalse($result['recorded']);
        $this->assertSame('task_not_active', $result['reason']);
    }

    public function testHeartbeatExtendsLease(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_expires_at' => now()
                ->addMinute(),
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertTrue($result['renewed']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);
        $this->assertSame('leased', $result['task_status']);

        $task->refresh();
        $this->assertTrue($task->lease_expires_at->isAfter(now()->addMinutes(4)));
    }

    public function testHeartbeatRejectsNonLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertFalse($result['renewed']);
        $this->assertSame('task_not_leased', $result['reason']);
    }

    public function testHeartbeatRejectsTaskOnTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => $closedAt,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_expires_at' => now()
                ->addMinute(),
        ]);

        $result = $this->bridge->heartbeat($task->id);

        $this->assertFalse($result['renewed']);
        $this->assertSame('run_closed', $result['reason']);
        $this->assertSame('cancelled', $result['run_status']);
        $this->assertSame('cancelled', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
    }

    public function testStatusReturnsLeasedTaskMetadata(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 2,
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame('leased', $result['task_status']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($run->workflow_instance_id, $result['workflow_instance_id']);
        $this->assertSame('worker-1', $result['lease_owner']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertFalse($result['lease_expired']);
        $this->assertSame(2, $result['attempt_count']);
        $this->assertNull($result['reason']);
    }

    public function testStatusDetectsExpiredLease(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subMinute(),
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame('leased', $result['task_status']);
        $this->assertTrue($result['lease_expired']);
        $this->assertSame('worker-1', $result['lease_owner']);
        $this->assertNull($result['reason']);
    }

    public function testStatusReturnsReadyTaskWithNoLease(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame('ready', $result['task_status']);
        $this->assertNull($result['lease_owner']);
        $this->assertNull($result['lease_expires_at']);
        $this->assertFalse($result['lease_expired']);
        $this->assertNull($result['reason']);
    }

    public function testStatusReturnsTaskNotFound(): void
    {
        $result = $this->bridge->status('nonexistent-task-id');

        $this->assertSame('nonexistent-task-id', $result['task_id']);
        $this->assertNull($result['task_status']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    public function testStatusRejectsActivityTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame('task_not_workflow', $result['reason']);
    }

    public function testStatusReturnsRunStatusFromRun(): void
    {
        $run = $this->createWaitingRun();
        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => $closedAt,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertSame('cancelled', $result['run_status']);
        $this->assertSame('cancelled', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
        $this->assertSame('leased', $result['task_status']);
    }

    public function testStatusNormalizesNullAttemptCount(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 0,
        ]);

        $result = $this->bridge->status($task->id);

        $this->assertNull($result['attempt_count']);
    }

    public function testClaimStatusRejectsActivityTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
        $this->assertSame('task_not_workflow', $result['reason']);
    }

    // --- poll() compatibility filter ---

    public function testPollFiltersByCompatibility(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-b',
        ]);

        $results = $this->bridge->poll(null, null, 10, 'build-a');

        $this->assertCount(1, $results);
        $this->assertSame('build-a', $results[0]['compatibility']);
    }

    public function testPollWithNullCompatibilityReturnsAll(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-b',
        ]);

        $results = $this->bridge->poll(null, null, 10, null);

        $this->assertCount(2, $results);
    }

    public function testPollFiltersByWorkflowType(): void
    {
        $matchingRun = $this->createWaitingRun();
        $otherRun = $this->createWaitingRun();

        $otherRun->forceFill([
            'workflow_type' => 'other-workflow-type',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $matchingRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $otherRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 10, null, null, ['test-greeting-workflow']);

        $this->assertCount(1, $results);
        $this->assertSame($matchingRun->id, $results[0]['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
    }

    public function testPollFiltersByWorkflowTypeBeforeApplyingLimit(): void
    {
        $firstRun = $this->createWaitingRun();
        $firstRun->forceFill([
            'workflow_type' => 'other-workflow-one',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $firstRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(3),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $secondRun = $this->createWaitingRun();
        $secondRun->forceFill([
            'workflow_type' => 'other-workflow-two',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $secondRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(2),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $matchingRun = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $matchingRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 2, null, null, ['test-greeting-workflow']);

        $this->assertCount(1, $results);
        $this->assertSame($matchingRun->id, $results[0]['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $results[0]['workflow_type']);
    }

    public function testPollByWorkflowTypeDeliversTaskForUnconfiguredPolyglotTypeOnSharedQueue(): void
    {
        // Polyglot routing contract: a worker filters its workflow-task
        // poll by an exact-string workflow_type, and the bridge must
        // return matching ready tasks regardless of whether the type-key
        // resolves to a loadable workflow class. The smoke that exposed
        // this on a real MySQL backend was a PHP-authored workflow whose
        // type-key is dotted and language-neutral. The cross-language
        // smoke runs against MySQL while this test runs against the
        // backends in CI; both must surface the matching task with the
        // same query so a regression here is caught at the package level
        // before the server image is published.
        $instance = WorkflowInstance::query()->create([
            // workflow_class is an unloadable string on purpose — mirrors
            // the polyglot smoke shape where the workflow class lives on
            // the PHP worker, not the server.
            'workflow_class' => 'polyglot.contract.PhpToPythonWorkflow',
            'workflow_type' => 'polyglot.contract.PhpToPythonWorkflow',
            'namespace' => 'default',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'polyglot.contract.PhpToPythonWorkflow',
            'workflow_type' => 'polyglot.contract.PhpToPythonWorkflow',
            'namespace' => 'default',
            'status' => RunStatus::Pending->value,
            'arguments' => Serializer::serialize(['polyglot']),
            'connection' => 'redis',
            'queue' => 'polyglot-contract-shared',
            'compatibility' => null,
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
            'last_history_sequence' => 0,
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'polyglot-contract-shared',
            'compatibility' => null,
        ]);

        $results = $this->bridge->poll(
            null,
            'polyglot-contract-shared',
            10,
            null,
            'default',
            ['polyglot.contract.PhpToPythonWorkflow'],
        );

        $this->assertCount(1, $results);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame('polyglot.contract.PhpToPythonWorkflow', $results[0]['workflow_type']);
        $this->assertSame('polyglot-contract-shared', $results[0]['queue']);
    }

    // --- complete() ---

    public function testCompleteWithWorkflowCompletionClosesRun(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
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

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('Hello, Taylor'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertNull($result['reason']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('completed', $run->closed_reason);
        $this->assertNotNull($run->closed_at);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $completionEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->first();

        $this->assertNotNull($completionEvent);
    }

    public function testLateExternalWorkflowCompletionClosesRunWithTypedRunTimeout(): void
    {
        $run = $this->createWaitingRun();
        $run->instance->forceFill([
            'execution_timeout_seconds' => 30,
        ])->save();
        $run->forceFill([
            'execution_deadline_at' => now()->addSeconds(28),
            'run_timeout_seconds' => 1,
            'run_deadline_at' => now()->subSecond(),
        ])->save();

        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('too late'),
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('failed', $result['run_status']);
        $this->assertSame('run_timed_out', $result['reason']);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $this->assertSame('timeout', $failure->failure_category->value);
        $this->assertSame('timeout', $failure->propagation_kind);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimedOut->value)
            ->firstOrFail();
        $this->assertSame('run_timeout', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame('timeout', $timedOutEvent->payload['failure_category']);

        $snapshots = FailureSnapshots::forRun($run->fresh(['historyEvents', 'failures']));
        $this->assertSame('run_timeout', $snapshots[0]['reason']);
        $this->assertSame('timeout', $snapshots[0]['failure_category']);

        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
        ]);
    }

    public function testCompleteWithWorkflowCompletionPreservesExternalResultPayloadCodec(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new NamespacedExternalPayloadStoragePolicy($driver, 'default'),
        );

        $run = $this->createWaitingRun('default');
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $expected = ['message' => str_repeat('Y', 64)];
        $payload = Serializer::serializeWithCodec($codec, $expected);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => $payload,
                'payload_codec' => $codec,
            ],
        ]);

        $this->assertTrue($result['completed']);

        $run->refresh();
        $this->assertIsString($run->output);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $run->output);
        $this->assertSame($expected, $run->workflowOutput());

        $completionEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->firstOrFail();

        $this->assertSame($codec, $completionEvent->payload['payload_codec'] ?? null);
        $this->assertSame($codec, $completionEvent->payload['output']['codec'] ?? null);
        $this->assertSame($codec, $completionEvent->payload['output']['external_storage']['codec'] ?? null);
    }

    public function testCompleteWithWorkflowCompletionPreservesResultEnvelopeCodec(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new NamespacedExternalPayloadStoragePolicy($driver, 'default'),
        );

        $run = $this->createWaitingRun('default');
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $expected = ['message' => str_repeat('Y', 64)];
        $payload = Serializer::serializeWithCodec($codec, $expected);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => [
                    'codec' => $codec,
                    'blob' => $payload,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);

        $run->refresh();
        $this->assertSame($expected, $run->workflowOutput());

        $completionEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->firstOrFail();

        $this->assertSame($codec, $completionEvent->payload['payload_codec'] ?? null);
        $this->assertSame($codec, $completionEvent->payload['output']['codec'] ?? null);
        $this->assertSame($codec, $completionEvent->payload['output']['external_storage']['codec'] ?? null);
    }

    public function testCompleteWithWorkflowFailureFailsRun(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
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

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'compensation failed for unknown: activity failed',
                'exception_class' => RuntimeException::class,
                'exception_type' => RuntimeException::class,
                'exception' => [
                    'class' => RuntimeException::class,
                    'type' => 'TypedCancelFlightError',
                    'message' => 'cancel_flight typed compensation failure',
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('failed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('failed', $run->closed_reason);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);

        $failureEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->first();

        $this->assertNotNull($failureEvent);
        $this->assertSame('compensation failed for unknown: activity failed', $failureEvent->payload['message']);
        $this->assertSame('TypedCancelFlightError', $failureEvent->payload['exception_type']);
        $this->assertSame('cancel_flight typed compensation failure', $failureEvent->payload['exception']['message'] ?? null);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame('compensation failed for unknown: activity failed', $failure->message);
        $this->assertSame(RuntimeException::class, $failure->exception_class);

        $snapshots = FailureSnapshots::forRun($run->fresh(['historyEvents', 'failures']));
        $this->assertSame('TypedCancelFlightError', $snapshots[0]['exception_type'] ?? null);
        $this->assertSame('cancel_flight typed compensation failure', $snapshots[0]['message'] ?? null);
    }

    public function testCompleteRejectsNonLeasedTask(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('task_not_leased', $result['reason']);
    }

    public function testCompleteRejectsEmptyCommands(): void
    {
        $result = $this->bridge->complete('any-task', []);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteRejectsInvalidOpenWaitTimeoutsBeforeTaskLookup(): void
    {
        $cases = [
            'condition negative timeout' => [
                'type' => 'open_condition_wait',
                'timeout_seconds' => -1,
            ],
            'condition string timeout' => [
                'type' => 'open_condition_wait',
                'timeout_seconds' => '30',
            ],
            'signal negative timeout' => [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => -1,
            ],
            'signal string timeout' => [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => '30',
            ],
        ];

        foreach ($cases as $label => $command) {
            $result = $this->bridge->complete('missing-task-'.$label, [$command]);

            $this->assertFalse($result['completed'], $label);
            $this->assertSame('invalid_commands', $result['reason'], $label);
        }
    }

    public function testCompleteRejectsMultipleTerminalCommands(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'complete_workflow',
            ],
            [
                'type' => 'fail_workflow',
                'message' => 'oops',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteRejectsTerminalRun(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => '"done"',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('run_already_closed', $result['reason']);
    }

    public function testCompleteRejectsNonExistentTask(): void
    {
        $result = $this->bridge->complete('nonexistent', [
            [
                'type' => 'complete_workflow',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('task_not_found', $result['reason']);
    }

    // --- complete() with non-terminal commands ---

    public function testCompleteRecordsSideEffectHistory(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $serialized = Serializer::serialize([
            'seed' => 123,
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_side_effect',
                'result' => $serialized,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->payload['sequence']);
        $this->assertSame($serialized, $event->payload['result']);
    }

    public function testCompleteExternalizesLargeSideEffectResultPayload(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);
        $run = $this->createWaitingRun();
        $run->forceFill([
            'payload_codec' => 'avro',
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $serialized = Serializer::serializeWithCodec('avro', [
            'seed' => str_repeat('x', 256),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_side_effect',
                'result' => $serialized,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded->value)
            ->firstOrFail();

        $this->assertSame(1, $event->payload['sequence']);
        $this->assertIsArray($event->payload['result']);
        $this->assertSame('avro', $event->payload['result']['codec']);
        $this->assertArrayHasKey('external_storage', $event->payload['result']);
        $this->assertArrayNotHasKey('blob', $event->payload['result']);
        $this->assertSame(
            ExternalPayloadReference::SCHEMA,
            $event->payload['result']['external_storage']['schema'],
        );
        $this->assertSame(
            $serialized,
            ExternalPayloads::payloadBlob($event->payload['result'], 'avro', null),
        );
    }

    public function testCompleteExternalizesSideEffectResultWithCommandPayloadCodec(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);
        $run = $this->createWaitingRun();
        $run->forceFill([
            'payload_codec' => 'avro',
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $codec = 'workflow-serializer-y';
        $serialized = Serializer::serializeWithCodec($codec, [
            'seed' => str_repeat('x', 256),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_side_effect',
                'result' => $serialized,
                'payload_codec' => $codec,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded->value)
            ->firstOrFail();

        $this->assertIsArray($event->payload['result']);
        $this->assertSame($codec, $event->payload['result']['codec']);
        $this->assertArrayHasKey('external_storage', $event->payload['result']);
        $this->assertSame($serialized, ExternalPayloads::payloadBlob($event->payload['result'], $codec, null));
    }

    public function testCompleteRecordsVersionMarkerBeforeWorkflowCompletion(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'record_version_marker',
                'change_id' => 'external-step',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 2,
            ],
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('done'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(HistoryEventType::VersionMarkerRecorded, $events[0]->event_type);
        $this->assertSame('external-step', $events[0]->payload['change_id']);
        $this->assertSame(2, $events[0]->payload['version']);
        $this->assertSame(HistoryEventType::WorkflowCompleted, $events[1]->event_type);
    }

    public function testCompleteUpsertsSearchAttributesAndProjectsSummary(): void
    {
        $run = $this->createWaitingRun();
        $this->createSearchAttribute($run, 'remove_me', 'legacy');
        $this->createSearchAttribute($run, 'tenant', 'acme');

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'env' => 'staging',
                    'remove_me' => null,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $runAttrs = $run->typedSearchAttributes();
        $this->assertSame([
            'env' => 'staging',
            'tenant' => 'acme',
        ], $runAttrs);

        $summary = WorkflowRunSummary::query()
            ->whereKey($run->id)
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame($runAttrs, $summary->getTypedSearchAttributes());

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->payload['sequence']);
        $this->assertSame([
            'env' => 'staging',
            'remove_me' => null,
        ], $event->payload['attributes']);
        $this->assertSame($runAttrs, $event->payload['merged']);
    }

    public function testCompleteRejectsIncompatibleDeclaredSearchAttributeType(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'tags' => 'alpha',
                ],
                'attribute_types' => [
                    'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
                ],
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);

        $task->refresh();
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame(0, WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', 'tags')
            ->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->count());
    }

    public function testCompleteUpdateCommandClosesAcceptedUpdateLifecycle(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $resultPayload = Serializer::serializeWithCodec('avro', [
            'approved' => true,
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => $resultPayload,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $update->refresh();
        $workflowCommand->refresh();
        $task->refresh();

        $this->assertSame('completed', $update->status->value);
        $this->assertSame('update_completed', $update->outcome->value);
        $this->assertSame($resultPayload, $update->result);
        $this->assertSame(1, $update->workflow_sequence);
        $this->assertNotNull($update->applied_at);
        $this->assertNotNull($update->closed_at);
        $this->assertSame('update_completed', $workflowCommand->outcome->value);
        $this->assertNotNull($workflowCommand->applied_at);
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::UpdateApplied->value,
                HistoryEventType::UpdateCompleted->value,
            ])
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(HistoryEventType::UpdateApplied, $events[0]->event_type);
        $this->assertSame($update->id, $events[0]->payload['update_id']);
        $this->assertSame(1, $events[0]->payload['sequence']);
        $this->assertSame(HistoryEventType::UpdateCompleted, $events[1]->event_type);
        $this->assertSame($resultPayload, $events[1]->payload['result']);
    }

    public function testCompleteUpdateCommandExternalizesLargeResultPayload(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);
        $run = $this->createWaitingRun();
        $run->forceFill([
            'payload_codec' => 'avro',
        ])->save();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $resultPayload = Serializer::serializeWithCodec('avro', [
            'approved' => true,
            'note' => str_repeat('r', 256),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => $resultPayload,
            ],
        ]);

        $this->assertTrue($result['completed']);

        $update->refresh();
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $update->result);
        $this->assertSame([
            'approved' => true,
            'note' => str_repeat('r', 256),
        ], $update->updateResult());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::UpdateCompleted->value)
            ->firstOrFail();

        $this->assertIsArray($event->payload['result']);
        $this->assertSame('avro', $event->payload['result']['codec']);
        $this->assertArrayHasKey('external_storage', $event->payload['result']);
        $this->assertArrayNotHasKey('blob', $event->payload['result']);
        $this->assertSame(
            ExternalPayloadReference::SCHEMA,
            $event->payload['result']['external_storage']['schema'],
        );
        $this->assertSame(
            $resultPayload,
            ExternalPayloads::payloadBlob($event->payload['result'], 'avro', null),
        );

        $updateRows = RunUpdateView::forRun($run->fresh());
        $updateRow = collect($updateRows)
            ->first(static fn (array $row): bool => ($row['id'] ?? null) === $update->id);

        $this->assertIsArray($updateRow);
        $this->assertTrue($updateRow['result_available']);
        $this->assertIsArray($updateRow['result']);
        $this->assertArrayHasKey('external_storage', $updateRow['result']);
        $this->assertArrayNotHasKey('blob', $updateRow['result']);

        $detail = RunDetailView::forRun($run->fresh());
        $detailUpdate = collect($detail['updates'])
            ->first(static fn (array $row): bool => ($row['id'] ?? null) === $update->id);
        $detailCommand = collect($detail['commands'])
            ->first(static fn (array $row): bool => ($row['update_id'] ?? null) === $update->id);
        $timelineEntry = collect(HistoryTimeline::fromHistory($run->fresh()))
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'UpdateCompleted');

        $this->assertIsArray($detailUpdate);
        $this->assertSame($updateRow['result'], $detailUpdate['result']);
        $this->assertIsArray($detailCommand);
        $this->assertSame($updateRow['result'], $detailCommand['result']);
        $this->assertIsArray($timelineEntry);
        $this->assertSame('update', $timelineEntry['kind']);
        $this->assertSame('approve', $timelineEntry['update_name']);
        $this->assertArrayNotHasKey('result', $timelineEntry);

        $export = HistoryExport::forRun($run->fresh());
        $exportUpdate = collect($export['updates'])
            ->first(static fn (array $row): bool => ($row['id'] ?? null) === $update->id);
        $exportEvent = collect($export['history_events'])
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'UpdateCompleted');
        $manifestEntry = collect($export['payload_manifest']['entries'])
            ->first(static fn (array $entry): bool => ($entry['path'] ?? null) === 'updates.0.result');

        $this->assertIsArray($exportUpdate);
        $this->assertIsArray($exportUpdate['result']);
        $this->assertArrayHasKey('external_storage', $exportUpdate['result']);
        $this->assertArrayNotHasKey('blob', $exportUpdate['result']);
        $this->assertIsArray($exportEvent);
        $this->assertArrayHasKey('external_storage', $exportEvent['payload']['result']);
        $this->assertIsArray($manifestEntry);
        $this->assertSame('external-storage-reference', $manifestEntry['encoding']);
        $this->assertSame('external_storage_reference', $manifestEntry['diagnostic']);

        $replayedRun = (new WorkflowReplayer())->runFromHistoryExport($export);
        $replayedUpdate = collect(RunUpdateView::forRun($replayedRun))
            ->first(static fn (array $row): bool => ($row['id'] ?? null) === $update->id);

        $this->assertIsArray($replayedUpdate);
        $this->assertIsArray($replayedUpdate['result']);
        $this->assertArrayHasKey('external_storage', $replayedUpdate['result']);
        $this->assertArrayNotHasKey('blob', $replayedUpdate['result']);
    }

    public function testCompleteUpdateCommandUsesCommandPayloadCodecForResultPayload(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);
        $run = $this->createWaitingRun();
        $run->forceFill([
            'payload_codec' => 'avro',
        ])->save();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $codec = 'workflow-serializer-y';
        $resultPayload = Serializer::serializeWithCodec($codec, [
            'approved' => true,
            'note' => str_repeat('r', 256),
        ]);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => $resultPayload,
                'payload_codec' => $codec,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::UpdateCompleted->value)
            ->firstOrFail();

        $this->assertIsArray($event->payload['result']);
        $this->assertSame($codec, $event->payload['result']['codec']);
        $this->assertSame($resultPayload, ExternalPayloads::payloadBlob($event->payload['result'], $codec, null));

        $updateRow = collect(RunUpdateView::forRun($run->fresh()))
            ->first(static fn (array $row): bool => ($row['id'] ?? null) === $update->id);

        $this->assertIsArray($updateRow);
        $this->assertTrue($updateRow['result_available']);
        $this->assertIsArray($updateRow['result']);
        $this->assertSame($codec, $updateRow['result']['codec']);
        $this->assertArrayHasKey('external_storage', $updateRow['result']);
    }

    public function testFailUpdateCommandClosesAcceptedUpdateLifecycleWithFailure(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forUpdate($update),
        ])->save();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_update',
                'update_id' => $update->id,
                'message' => 'approval denied',
                'exception_class' => RuntimeException::class,
                'exception_type' => 'approval_denied',
                'non_retryable' => true,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $update->refresh();
        $workflowCommand->refresh();
        $task->refresh();

        $this->assertSame('failed', $update->status->value);
        $this->assertSame('update_failed', $update->outcome->value);
        $this->assertSame('approval denied', $update->failure_message);
        $this->assertSame(1, $update->workflow_sequence);
        $this->assertNotNull($update->failure_id);
        $this->assertSame('update_failed', $workflowCommand->outcome->value);
        $this->assertSame(TaskStatus::Completed, $task->status);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->findOrFail($update->failure_id);

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame($workflowCommand->id, $failure->source_id);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertTrue($failure->non_retryable);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::UpdateCompleted->value)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame(HistoryEventType::UpdateCompleted, $events[0]->event_type);
        $this->assertSame($update->id, $events[0]->payload['update_id']);
        $this->assertSame($failure->id, $events[0]->payload['failure_id']);
        $this->assertSame('approval_denied', $events[0]->payload['exception_type']);
    }

    public function testUpdateCommandRejectsMismatchedTaskResumeContext(): void
    {
        $run = $this->createWaitingRun();
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $workflowCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'approve',
                'arguments' => [true],
            ]),
            'source' => 'worker-protocol',
            'accepted_at' => now(),
        ]);

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $workflowCommand->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'accepted',
            'arguments' => Serializer::serializeWithCodec('avro', [true]),
            'payload_codec' => 'avro',
            'command_sequence' => $workflowCommand->command_sequence,
            'accepted_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $task->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'update',
                'workflow_update_id' => '01MISMATCHEDUPDATE000000000',
            ],
        ])->save();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_update',
                'update_id' => $update->id,
                'result' => Serializer::serializeWithCodec('avro', [
                    'approved' => true,
                ]),
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);

        $update->refresh();
        $task->refresh();

        $this->assertSame('accepted', $update->status->value);
        $this->assertSame(TaskStatus::Leased, $task->status);

        $historyEventCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count();

        $this->assertSame(0, $historyEventCount);
    }

    public function testCompleteRejectsMalformedRecordVersionMarkerCommand(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'record_version_marker',
                'change_id' => 'external-step',
                'version' => 3,
                'min_supported' => 1,
                'max_supported' => 2,
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testCompleteSchedulesActivity(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => Serializer::serialize(['Taylor']),
                'queue' => 'activities',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertNull($result['reason']);
        $this->assertCount(1, $result['created_task_ids']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->lease_expires_at);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('test-greeting-activity', $execution->activity_type);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame('activities', $execution->queue);

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertNotNull($activityTask);
        $this->assertSame(TaskStatus::Ready, $activityTask->status);
        $this->assertSame('activities', $activityTask->queue);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($execution->id, $scheduledEvent->payload['activity_execution_id']);
    }

    public function testCompleteSchedulesActivityPreservesExternalArgumentsPayloadCodec(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new NamespacedExternalPayloadStoragePolicy($driver, 'default'),
        );

        $run = $this->createWaitingRun('default');
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $expected = ['Taylor', str_repeat('Y', 64)];
        $arguments = Serializer::serializeWithCodec($codec, $expected);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => $arguments,
                'payload_codec' => $codec,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame($codec, $execution->payload_codec);
        $this->assertIsString($execution->arguments);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $execution->arguments);
        $this->assertSame($expected, $execution->activityArguments());

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();

        $this->assertSame($codec, $scheduledEvent->payload['activity']['payload_codec'] ?? null);
        $this->assertSame($codec, $scheduledEvent->payload['activity']['arguments']['codec'] ?? null);
        $this->assertSame($codec, $scheduledEvent->payload['activity']['arguments']['external_storage']['codec'] ?? null);
    }

    public function testCompleteSchedulesActivityPreservesArgumentsEnvelopeCodec(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new NamespacedExternalPayloadStoragePolicy($driver, 'default'),
        );

        $run = $this->createWaitingRun('default');
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $expected = ['Taylor', str_repeat('Y', 64)];
        $arguments = Serializer::serializeWithCodec($codec, $expected);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => [
                    'codec' => $codec,
                    'blob' => $arguments,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame($codec, $execution->payload_codec);
        $this->assertSame($expected, $execution->activityArguments());

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();

        $this->assertSame($codec, $scheduledEvent->payload['activity']['payload_codec'] ?? null);
        $this->assertSame($codec, $scheduledEvent->payload['activity']['arguments']['codec'] ?? null);
        $this->assertSame($codec, $scheduledEvent->payload['activity']['arguments']['external_storage']['codec'] ?? null);
    }

    public function testCompleteSchedulesTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(300, $timer->delay_seconds);
        $this->assertNotNull($timer->fire_at);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertSame(TaskStatus::Ready, $timerTask->status);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($timer->id, $scheduledEvent->payload['timer_id']);
    }

    public function testTimerTaskCreatesResumeTaskWithTimerContext(): void
    {
        Queue::fake();

        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][0]);
        $timerId = $timerTask->payload['timer_id'] ?? null;

        $this->assertIsString($timerId);

        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSame('timer', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame("timer:{$timerId}", $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('timer', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($timerId, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($timerId, $resumeTask->payload['timer_id'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(HistoryEventType::TimerFired->value, $resumeTask->payload['workflow_event_type'] ?? null);
    }

    public function testRunTimerTaskUsesHistoryProjectionRoleBinding(): void
    {
        Queue::fake();

        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($result['created_task_ids'][0]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

        $this->assertGreaterThanOrEqual(2, count($customRole->calls));
        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $run->id]],
            array_slice($customRole->calls, 0, 2),
        );
    }

    public function testBridgeSourcesDoNotCallRunSummaryProjectorDirectly(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workflowBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultWorkflowTaskBridge.php');
        $activityBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultActivityTaskBridge.php');

        $this->assertIsString($workflowBridgeSource);
        $this->assertIsString($activityBridgeSource);
        $this->assertStringNotContainsString('RunSummaryProjector::project(', $workflowBridgeSource);
        $this->assertStringNotContainsString('RunSummaryProjector::project(', $activityBridgeSource);
    }

    public function testBridgeSourcesDoNotMutateWorkflowRunSummaryDirectly(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workflowBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultWorkflowTaskBridge.php');
        $activityBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultActivityTaskBridge.php');

        $this->assertIsString($workflowBridgeSource);
        $this->assertIsString($activityBridgeSource);
        $this->assertStringNotContainsString(
            'WorkflowRunSummary::query()',
            $workflowBridgeSource,
            'Bridge must mutate run summaries via HistoryProjectionRole, not direct queries.',
        );
        $this->assertStringNotContainsString(
            'WorkflowRunSummary::query()',
            $activityBridgeSource,
            'Bridge must mutate run summaries via HistoryProjectionRole, not direct queries.',
        );
    }

    public function testActivityBridgeProjectRunHelperOwnsRelationHydration(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $activityBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultActivityTaskBridge.php');

        $this->assertIsString($activityBridgeSource);

        $this->assertStringContainsString(
            'PROJECTION_RUN_RELATIONS',
            $activityBridgeSource,
            'Activity bridge must declare a PROJECTION_RUN_RELATIONS constant so the relation list lives in one place.',
        );

        $this->assertMatchesRegularExpression(
            '/private static function projectRun\(WorkflowRun \$run, array \$with = \[\]\): void/',
            $activityBridgeSource,
            'projectRun() must accept the relation list so call sites do not hydrate inline.',
        );

        $this->assertSame(
            1,
            substr_count($activityBridgeSource, 'historyProjectionRole()->projectRun('),
            'Only the projectRun() helper may dispatch into HistoryProjectionRole::projectRun().',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/->fresh\(\[[^\]]*\]\)\s*\n?\s*\?\?\s*\$\w+\s*\)/',
            $activityBridgeSource,
            'Projection call sites must not inline `->fresh([...]) ?? $run` — '
                . 'the projectRun() helper owns relation hydration.',
        );
    }

    public function testWorkflowBridgeProjectRunHelperOwnsRelationHydration(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workflowBridgeSource = file_get_contents($repoRoot . '/src/V2/Support/DefaultWorkflowTaskBridge.php');

        $this->assertIsString($workflowBridgeSource);

        $this->assertStringContainsString(
            'PROJECTION_RUN_RELATIONS',
            $workflowBridgeSource,
            'Workflow bridge must declare a PROJECTION_RUN_RELATIONS constant so the relation list lives in one place.',
        );

        $this->assertStringContainsString(
            'PROJECTION_RUN_RELATIONS_WITH_TIMERS',
            $workflowBridgeSource,
            'Workflow bridge must declare PROJECTION_RUN_RELATIONS_WITH_TIMERS for the post-execute terminal projection.',
        );

        $this->assertStringContainsString(
            'PROJECTION_RUN_RELATIONS_WITH_HISTORY',
            $workflowBridgeSource,
            'Workflow bridge must declare PROJECTION_RUN_RELATIONS_WITH_HISTORY for terminal-write call sites.',
        );

        $this->assertStringContainsString(
            'PROJECTION_RUN_RELATIONS_WITH_CHILDREN',
            $workflowBridgeSource,
            'Workflow bridge must declare PROJECTION_RUN_RELATIONS_WITH_CHILDREN for the parent-resume rebuild path.',
        );

        $this->assertMatchesRegularExpression(
            '/private static function projectRun\(WorkflowRun \$run, array \$with = \[\]\): void/',
            $workflowBridgeSource,
            'projectRun() must accept the relation list so call sites do not hydrate inline.',
        );

        $this->assertSame(
            1,
            substr_count($workflowBridgeSource, 'historyProjectionRole()->projectRun('),
            'Only the projectRun() helper may dispatch into HistoryProjectionRole::projectRun().',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/->fresh\(\[[^\]]*\]\)\s*\n?\s*\?\?\s*\$\w+\s*\)/',
            $workflowBridgeSource,
            'Projection call sites must not inline `->fresh([...]) ?? $run` — '
                . 'the projectRun() helper owns relation hydration.',
        );

        $this->assertStringNotContainsString(
            'projectRunSummary',
            $workflowBridgeSource,
            'projectRunSummary() must be removed in favor of the unified projectRun() helper.',
        );
    }

    public function testCompleteOpenConditionWaitWithoutTimeoutRecordsEventAndMarksWaiting(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'order-ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('order-ready', $event->payload['condition_key'] ?? null);
        $this->assertIsString($event->payload['condition_wait_id'] ?? null);
        $this->assertSame(1, $event->payload['sequence'] ?? null);
        $this->assertArrayNotHasKey('timeout_seconds', $event->payload);

        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testCompleteOpenConditionWaitWithTimeoutSchedulesConditionTimeoutTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'payment-cleared',
                'condition_definition_fingerprint' => 'fp-1',
                'timeout_seconds' => 45,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('payment-cleared', $opened->payload['condition_key'] ?? null);
        $this->assertSame('fp-1', $opened->payload['condition_definition_fingerprint'] ?? null);
        $this->assertSame(45, $opened->payload['timeout_seconds'] ?? null);

        $waitId = $opened->payload['condition_wait_id'] ?? null;
        $this->assertIsString($waitId);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(45, $timer->delay_seconds);
        $this->assertSame(1, $timer->sequence);

        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();

        $this->assertSame('condition_timeout', $scheduled->payload['timer_kind'] ?? null);
        $this->assertSame($waitId, $scheduled->payload['condition_wait_id'] ?? null);
        $this->assertSame('payment-cleared', $scheduled->payload['condition_key'] ?? null);
        $this->assertSame('fp-1', $scheduled->payload['condition_definition_fingerprint'] ?? null);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $timerTask->payload['timer_id'] ?? null);
        $this->assertSame($waitId, $timerTask->payload['condition_wait_id'] ?? null);
        $this->assertSame('payment-cleared', $timerTask->payload['condition_key'] ?? null);
    }

    public function testCompleteOpenConditionWaitWithZeroTimeoutDoesNotScheduleTimer(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([], $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame(0, $opened->payload['timeout_seconds'] ?? null);
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testCompleteOpenSignalWaitRecordsEventAndOptionalTimeout(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'increment',
                'timeout_seconds' => 45,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('increment', $opened->payload['signal_name'] ?? null);
        $this->assertSame(1, $opened->payload['sequence'] ?? null);
        $this->assertSame(45, $opened->payload['timeout_seconds'] ?? null);
        $this->assertIsString($opened->payload['signal_wait_id'] ?? null);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(45, $timer->delay_seconds);
    }

    public function testCompleteOpenSignalWaitWithZeroTimeoutFiresImmediately(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->count());

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();
        $waitId = $opened->payload['signal_wait_id'] ?? null;

        $this->assertIsString($waitId);
        $this->assertSame('advance', $opened->payload['signal_name'] ?? null);
        $this->assertSame(0, $opened->payload['timeout_seconds'] ?? null);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(TimerStatus::Fired, $timer->status);
        $this->assertSame(0, $timer->delay_seconds);

        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();
        $fired = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->firstOrFail();

        foreach ([$scheduled, $fired] as $event) {
            $this->assertSame($timer->id, $event->payload['timer_id'] ?? null);
            $this->assertSame($waitId, $event->payload['signal_wait_id'] ?? null);
            $this->assertSame('advance', $event->payload['signal_name'] ?? null);
            $this->assertSame('signal_timeout', $event->payload['timer_kind'] ?? null);
            $this->assertSame(0, $event->payload['delay_seconds'] ?? null);
        }

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame(TaskType::Workflow, $resumeTask->task_type);
        $this->assertSame(TaskStatus::Ready, $resumeTask->status);
        $this->assertSame('signal', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame('timer', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($timer->id, $resumeTask->payload['timer_id'] ?? null);
        $this->assertSame('signal_timeout', $resumeTask->payload['timer_kind'] ?? null);
        $this->assertSame($waitId, $resumeTask->payload['signal_wait_id'] ?? null);
        $this->assertSame('advance', $resumeTask->payload['signal_name'] ?? null);
        $this->assertSame(HistoryEventType::TimerFired->value, $resumeTask->payload['workflow_event_type'] ?? null);

        $lateSignal = $this->recordReceivedSignal($run, 'advance', $waitId);

        $wait = collect(\Workflow\V2\Support\SignalWaits::forRun($run))
            ->firstWhere('signal_wait_id', $waitId);

        $this->assertIsArray($wait);
        $this->assertSame('resolved', $wait['status'] ?? null);
        $this->assertSame('timed_out', $wait['source_status'] ?? null);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)
            ->count());

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalApplied, [
            'workflow_command_id' => $lateSignal->workflow_command_id,
            'signal_id' => $lateSignal->id,
            'signal_name' => 'advance',
            'signal_wait_id' => $waitId,
            'sequence' => 1,
            'value' => Serializer::serialize(['late' => true]),
        ]);

        $wait = collect(\Workflow\V2\Support\SignalWaits::forRun($run))
            ->firstWhere('signal_wait_id', $waitId);

        $this->assertIsArray($wait);
        $this->assertSame('resolved', $wait['status'] ?? null);
        $this->assertSame('timed_out', $wait['source_status'] ?? null);
    }

    public function testCompleteOpenSignalWaitReusesMatchingBufferedSignalWaitId(): void
    {
        $run = $this->createWaitingRun();

        $unrelated = $this->recordReceivedSignal($run, 'finish', 'signal-wait-finish');
        $matching = $this->recordReceivedSignal($run, 'advance', 'signal-wait-advance');

        $unrelated->forceFill([
            'received_at' => now()->subSeconds(2),
            'created_at' => now()->subSeconds(2),
        ])->save();
        $matching->forceFill([
            'received_at' => now()->subSecond(),
            'created_at' => now()->subSecond(),
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('advance', $opened->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-advance', $opened->payload['signal_wait_id'] ?? null);

        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame($matching->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $signalTask->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-advance', $signalTask->payload['signal_wait_id'] ?? null);
    }

    public function testCompleteOpenSignalWaitWithZeroTimeoutUsesBufferedSignalBeforeTimeout(): void
    {
        $run = $this->createWaitingRun();
        $matching = $this->recordReceivedSignal($run, 'advance', 'signal-wait-advance');

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('signal-wait-advance', $opened->payload['signal_wait_id'] ?? null);
        $this->assertSame(0, $opened->payload['timeout_seconds'] ?? null);

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame(TaskType::Workflow, $signalTask->task_type);
        $this->assertSame('workflow_signal', $signalTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($matching->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $signalTask->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-advance', $signalTask->payload['signal_wait_id'] ?? null);
    }

    public function testCompleteOpenSignalWaitWithPositiveTimeoutUsesBufferedSignalWithoutTimer(): void
    {
        $run = $this->createWaitingRun();
        $matching = $this->recordReceivedSignal($run, 'advance', 'signal-wait-advance');

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => 45,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->count());

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('advance', $opened->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-advance', $opened->payload['signal_wait_id'] ?? null);
        $this->assertSame(45, $opened->payload['timeout_seconds'] ?? null);

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame(TaskType::Workflow, $signalTask->task_type);
        $this->assertSame('workflow_signal', $signalTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($matching->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $signalTask->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-advance', $signalTask->payload['signal_wait_id'] ?? null);
    }

    public function testCompleteOpenSignalWaitDoesNotEnqueueNonMatchingBufferedSignal(): void
    {
        $run = $this->createWaitingRun();
        $unrelated = $this->recordReceivedSignal($run, 'finish', 'signal-wait-finish');

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('advance', $opened->payload['signal_name'] ?? null);
        $this->assertNotSame($unrelated->signal_wait_id, $opened->payload['signal_wait_id'] ?? null);

        $openSignalTaskExists = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->get()
            ->contains(
                static fn (WorkflowTask $workflowTask): bool => ($workflowTask->payload['workflow_signal_id'] ?? null)
                    === $unrelated->id
            );

        $this->assertFalse($openSignalTaskExists);
    }

    public function testSignalResumeCompletionRecordsSignalAppliedForOpenSignalWait(): void
    {
        $run = $this->createWaitingRun();

        $openTask = $this->createLeasedTask($run);

        $openedResult = $this->bridge->complete($openTask->id, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
            ],
        ]);

        $this->assertTrue($openedResult['completed']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();
        $signalWaitId = $opened->payload['signal_wait_id'] ?? null;

        $this->assertIsString($signalWaitId);

        $signal = $this->recordReceivedSignal($run, 'advance', $signalWaitId);
        $resumeTask = $this->createLeasedTask($run);
        $resumeTask->forceFill([
            'payload' => WorkflowTaskPayload::forSignal($signal),
        ])->save();

        $completed = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'ok' => true,
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)
            ->firstOrFail();

        $this->assertSame('advance', $applied->payload['signal_name'] ?? null);
        $this->assertSame($signalWaitId, $applied->payload['signal_wait_id'] ?? null);
        $this->assertSame(1, $applied->payload['sequence'] ?? null);
        $this->assertSame('Ada', Serializer::unserialize($applied->payload['value']));

        $wait = collect(\Workflow\V2\Support\SignalWaits::forRun($run))
            ->firstWhere('signal_wait_id', $signalWaitId);

        $this->assertIsArray($wait);
        $this->assertSame('resolved', $wait['status'] ?? null);
        $this->assertSame('applied', $wait['source_status'] ?? null);

        $signal->refresh();
        $this->assertSame('applied', $signal->status->value);
        $this->assertSame(1, $signal->workflow_sequence);
    }

    public function testCompletingLeasedTaskAfterSignalArrivesEnqueuesSignalResumeTask(): void
    {
        $run = $this->createWaitingRun();
        $leasedTask = $this->createLeasedTask($run);
        $signal = $this->recordReceivedSignal($run);

        $result = $this->bridge->complete($leasedTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame(TaskType::Workflow, $signalTask->task_type);
        $this->assertSame(TaskStatus::Ready, $signalTask->status);
        $this->assertSame('signal', $signalTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame('workflow_signal', $signalTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($signal->id, $signalTask->payload['resume_source_id'] ?? null);
        $this->assertSame($signal->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $signalTask->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-race', $signalTask->payload['signal_wait_id'] ?? null);
    }

    public function testCompletingLeasedTaskAfterSignalArrivesForActivityWaitDoesNotEnqueueSignalResumeTask(): void
    {
        $run = $this->createWaitingRun();
        $leasedTask = $this->createLeasedTask($run);
        $signal = $this->recordReceivedSignal($run);

        $result = $this->bridge->complete($leasedTask->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'tests.example.activity',
                'arguments' => Serializer::serialize(['Ada']),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $activityTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();
        $openSignalTaskExists = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->get()
            ->contains(
                static fn (WorkflowTask $task): bool => ($task->payload['workflow_signal_id'] ?? null)
                    === $signal->id
            );

        $this->assertSame(TaskType::Activity, $activityTask->task_type);
        $this->assertFalse($openSignalTaskExists);
    }

    public function testCompletingUnconsumedSignalResumeDoesNotRequeueSameSignal(): void
    {
        $run = $this->createWaitingRun();
        $signal = $this->recordReceivedSignal($run);
        $resumeTask = $this->createLeasedTask($run);

        $resumeTask->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:' . $signal->id,
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => $signal->id,
                'workflow_signal_id' => $signal->id,
                'signal_name' => $signal->signal_name,
                'signal_wait_id' => $signal->signal_wait_id,
                'workflow_command_id' => $signal->workflow_command_id,
            ],
        ])->save();

        $result = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $openSignalTaskExists = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->get()
            ->contains(
                static fn (WorkflowTask $task): bool => ($task->payload['workflow_signal_id'] ?? null)
                    === $signal->id
            );

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame([], $result['created_task_ids']);
        $this->assertFalse($openSignalTaskExists);
    }

    public function testCompletingSignalResumeEnqueuesNextReceivedSignal(): void
    {
        $run = $this->createWaitingRun();
        $first = $this->recordReceivedSignal($run, 'increment', 'signal-wait-1');
        $second = $this->recordReceivedSignal($run, 'finish', 'signal-wait-2');
        $resumeTask = $this->createLeasedTask($run);

        $first->forceFill([
            'received_at' => now()->subSeconds(2),
            'created_at' => now()->subSeconds(2),
        ])->save();
        $second->forceFill([
            'received_at' => now()->subSecond(),
            'created_at' => now()->subSecond(),
        ])->save();

        $resumeTask->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:' . $first->id,
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => $first->id,
                'workflow_signal_id' => $first->id,
                'signal_name' => $first->signal_name,
                'signal_wait_id' => $first->signal_wait_id,
                'workflow_command_id' => $first->workflow_command_id,
            ],
        ])->save();

        $result = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame(TaskType::Workflow, $signalTask->task_type);
        $this->assertSame(TaskStatus::Ready, $signalTask->status);
        $this->assertSame($second->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('finish', $signalTask->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-2', $signalTask->payload['signal_wait_id'] ?? null);

        $signalTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        $secondResult = $this->bridge->complete($signalTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($secondResult['completed']);
        $this->assertSame('waiting', $secondResult['run_status']);
        $this->assertSame([], $secondResult['created_task_ids']);
    }

    public function testCompletingSignalResumeUsesCommandSequenceForRapidReceivedSignals(): void
    {
        $run = $this->createWaitingRun();

        $openTask = $this->createLeasedTask($run);
        $openedResult = $this->bridge->complete($openTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($openedResult['completed']);

        $first = $this->recordReceivedSignal($run, 'increment', 'signal-wait-1');
        $second = $this->recordReceivedSignal($run, 'increment', 'signal-wait-2');
        $third = $this->recordReceivedSignal($run, 'increment', 'signal-wait-3');
        $resumeTask = $this->createLeasedTask($run);

        $first->forceFill([
            'received_at' => now()->subSeconds(3),
            'created_at' => now()->subSeconds(3),
        ])->save();
        $third->forceFill([
            'received_at' => now()->subSeconds(2),
            'created_at' => now()->subSeconds(2),
        ])->save();
        $second->forceFill([
            'received_at' => now()->subSecond(),
            'created_at' => now()->subSecond(),
        ])->save();

        $resumeTask->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:' . $first->id,
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => $first->id,
                'workflow_signal_id' => $first->id,
                'signal_name' => $first->signal_name,
                'signal_wait_id' => $first->signal_wait_id,
                'workflow_command_id' => $first->workflow_command_id,
            ],
        ])->save();

        $result = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertCount(1, $result['created_task_ids']);

        $signalTask = WorkflowTask::query()
            ->whereKey($result['created_task_ids'][0])
            ->firstOrFail();

        $this->assertSame($second->id, $signalTask->payload['workflow_signal_id'] ?? null);
        $this->assertNotSame($third->id, $signalTask->payload['workflow_signal_id'] ?? null);

        $first->refresh();
        $third->refresh();

        $this->assertSame('applied', $first->status->value);
        $this->assertSame(1, $first->workflow_sequence);
        $this->assertNotNull($first->applied_at);
        $this->assertNotNull($first->closed_at);
        $this->assertSame('received', $third->status->value);
        $this->assertNull($third->workflow_sequence);

        $firstSatisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->get()
            ->first(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['workflow_signal_id'] ?? null) === $first->id);

        $this->assertNotNull($firstSatisfied);
        $this->assertSame(1, $firstSatisfied->payload['sequence'] ?? null);
        $this->assertNotNull(WorkflowCommand::query()
            ->whereKey($first->workflow_command_id)
            ->value('applied_at'));

        $signalTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        $secondResult = $this->bridge->complete($signalTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
            ],
        ]);

        $this->assertTrue($secondResult['completed']);
        $this->assertSame('waiting', $secondResult['run_status']);
        $this->assertCount(1, $secondResult['created_task_ids']);

        $thirdTask = WorkflowTask::query()
            ->whereKey($secondResult['created_task_ids'][0])
            ->firstOrFail();

        $second->refresh();

        $this->assertSame($third->id, $thirdTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame('applied', $second->status->value);
        $this->assertIsInt($second->workflow_sequence);
        $this->assertGreaterThan($first->workflow_sequence, $second->workflow_sequence);

        $secondSatisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->get()
            ->first(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['workflow_signal_id'] ?? null) === $second->id);

        $this->assertNotNull($secondSatisfied);
        $this->assertSame($second->workflow_sequence, $secondSatisfied->payload['sequence'] ?? null);
        $this->assertSame(['applied', 'applied', 'received'], WorkflowSignal::query()
            ->whereIn('id', [$first->id, $second->id, $third->id])
            ->orderBy('command_sequence')
            ->pluck('status')
            ->all());
    }

    public function testSignalResumeCompletionRecordsSatisfiedConditionWaitAndCancelsTimeout(): void
    {
        $run = $this->createWaitingRun();

        $openTask = $this->createLeasedTask($run);

        $openedResult = $this->bridge->complete($openTask->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval.ready',
                'condition_definition_fingerprint' => 'condition-fp-1',
                'timeout_seconds' => 60,
            ],
        ]);

        $this->assertTrue($openedResult['completed']);

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $conditionWaitId = $opened->payload['condition_wait_id'] ?? null;

        $this->assertIsString($conditionWaitId);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(TaskStatus::Ready, $timerTask->status);

        $resumeTask = $this->createLeasedTask($run);
        $resumeTask->forceFill([
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:signal-1',
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => 'signal-1',
                'workflow_signal_id' => 'signal-1',
                'signal_name' => 'advance',
                'signal_wait_id' => 'signal-wait-1',
            ],
        ])->save();

        $completed = $this->bridge->complete($resumeTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'approved' => true,
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        $satisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->firstOrFail();

        $this->assertSame($conditionWaitId, $satisfied->payload['condition_wait_id'] ?? null);
        $this->assertSame('approval.ready', $satisfied->payload['condition_key'] ?? null);
        $this->assertSame('condition-fp-1', $satisfied->payload['condition_definition_fingerprint'] ?? null);
        $this->assertSame(1, $satisfied->payload['sequence'] ?? null);
        $this->assertSame($timer->id, $satisfied->payload['timer_id'] ?? null);
        $this->assertSame(60, $satisfied->payload['timeout_seconds'] ?? null);
        $this->assertSame('signal-1', $satisfied->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $satisfied->payload['signal_name'] ?? null);
        $this->assertSame('signal-wait-1', $satisfied->payload['signal_wait_id'] ?? null);

        $timer->refresh();
        $timerTask->refresh();

        $this->assertSame(TimerStatus::Cancelled, $timer->status);
        $this->assertSame(TaskStatus::Cancelled, $timerTask->status);

        $cancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $cancelled->payload['timer_id'] ?? null);
        $this->assertSame($conditionWaitId, $cancelled->payload['condition_wait_id'] ?? null);
    }

    public function testCompleteStartsChildWorkflow(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);

        $childRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($childRun);
        $this->assertSame(RunStatus::Pending, $childRun->status);
        $this->assertSame('test-greeting-workflow', $childRun->workflow_type);

        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($childTask);
        $this->assertSame(TaskStatus::Ready, $childTask->status);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame($link->child_workflow_run_id, $scheduledEvent->payload['child_workflow_run_id']);

        $childStartedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();

        $this->assertNotNull($childStartedEvent);
    }

    public function testCompleteStartsChildWorkflowPreservesArgumentsEnvelopeCodec(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $arguments = Serializer::serializeWithCodec($codec, ['child-arg']);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => [
                    'codec' => $codec,
                    'blob' => $arguments,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame($codec, $childRun->payload_codec);
        $this->assertSame($arguments, $childRun->arguments);
        $this->assertSame(['child-arg'], $childRun->workflowArguments());
    }

    public function testCompleteStartsChildWorkflowWithParentNamespace(): void
    {
        $run = $this->createWaitingRun('production');

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);

        $childRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($childRun);
        $this->assertSame('production', $childRun->namespace);

        $this->assertSame(
            'production',
            WorkflowInstance::query()->whereKey($link->child_workflow_instance_id)->value('namespace'),
        );

        $this->assertSame(
            'production',
            WorkflowTask::query()
                ->where('workflow_run_id', $childRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->value('namespace'),
        );
    }

    public function testCompleteStartsChildWorkflowWithParentClosePolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'terminate',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('terminate', $link->parent_close_policy);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('terminate', $scheduledEvent->payload['parent_close_policy']);
    }

    public function testExternalWorkflowCompletionAppliesRequestCancelParentClosePolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'request_cancel',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $parentCloseTask = $this->createLeasedTask($run->fresh());

        $closed = $this->bridge->complete($parentCloseTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize(['parent' => 'done']),
            ],
        ]);

        $this->assertTrue($closed['completed']);
        $this->assertSame('completed', $closed['run_status']);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame(RunStatus::Cancelled, $childRun->status);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Cancelled, $childCall->status);

        $childTask->refresh();
        $this->assertSame(TaskStatus::Cancelled, $childTask->status);

        /** @var WorkflowHistoryEvent $applied */
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->firstOrFail();

        $this->assertSame('request_cancel', $applied->payload['policy'] ?? null);
        $this->assertSame($link->child_workflow_instance_id, $applied->payload['child_instance_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $applied->payload['child_run_id'] ?? null);
    }

    public function testExternalWorkflowFailureAppliesTerminateParentClosePolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'terminate',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $parentCloseTask = $this->createLeasedTask($run->fresh());

        $closed = $this->bridge->complete($parentCloseTask->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'parent failed after child start',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($closed['completed']);
        $this->assertSame('failed', $closed['run_status']);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame(RunStatus::Terminated, $childRun->status);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Terminated, $childCall->status);

        /** @var WorkflowHistoryEvent $applied */
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->firstOrFail();

        $this->assertSame('terminate', $applied->payload['policy'] ?? null);
    }

    public function testContinuedChildCompletionResolvesOriginalChildCall(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $continued = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['continued-child-arg']),
            ],
        ]);

        $this->assertTrue($continued['completed']);

        $childLinks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $childLinks);

        /** @var WorkflowLink $continuedLink */
        $continuedLink = $childLinks->last();

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->findOrFail($continuedLink->child_workflow_run_id);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Started, $childCall->status);
        $this->assertSame($continuedRun->id, $childCall->resolved_child_run_id);

        /** @var WorkflowTask $continuedTask */
        $continuedTask = WorkflowTask::query()
            ->where('workflow_run_id', $continuedRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($continuedTask->id, 'external-child-worker');

        $completed = $this->bridge->complete($continuedTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize(['child' => 'done']),
            ],
        ]);

        $this->assertTrue($completed['completed']);

        $childCall->refresh();
        $this->assertSame(ChildCallStatus::Completed, $childCall->status);
        $this->assertSame($continuedRun->id, $childCall->resolved_child_run_id);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame($initialLink->id, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame($continuedRun->id, $childCompleted->payload['child_workflow_run_id'] ?? null);

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSame($initialLink->id, $parentResumeTask->payload['child_call_id'] ?? null);
        $this->assertSame($continuedRun->id, $parentResumeTask->payload['child_workflow_run_id'] ?? null);
    }

    public function testParentClosePolicyClosesContinuedChildCall(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'request_cancel',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $continued = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['continued-child-arg']),
            ],
        ]);

        $this->assertTrue($continued['completed']);

        $childLinks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $childLinks);

        /** @var WorkflowLink $continuedLink */
        $continuedLink = $childLinks->last();

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->findOrFail($continuedLink->child_workflow_run_id);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Started, $childCall->status);
        $this->assertSame($continuedRun->id, $childCall->resolved_child_run_id);

        $parentCloseTask = $this->createLeasedTask($run->fresh());

        $closed = $this->bridge->complete($parentCloseTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize(['parent' => 'done']),
            ],
        ]);

        $this->assertTrue($closed['completed']);

        $continuedRun->refresh();
        $this->assertSame(RunStatus::Cancelled, $continuedRun->status);

        $childCall->refresh();
        $this->assertSame(ChildCallStatus::Cancelled, $childCall->status);
        $this->assertSame($continuedRun->id, $childCall->resolved_child_run_id);

        /** @var WorkflowHistoryEvent $applied */
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->firstOrFail();

        $this->assertSame('request_cancel', $applied->payload['policy'] ?? null);
        $this->assertSame($continuedRun->id, $applied->payload['child_run_id'] ?? null);
    }

    public function testChildStartAuthoritativeStateSurvivesChildCallProjectionInsertFailure(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $this->failChildCallProjectionWrites('insert');

        try {
            $result = $this->bridge->complete($task->id, [
                [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'test-greeting-workflow',
                    'arguments' => Serializer::serialize(['child-arg']),
                    'parent_close_policy' => 'request_cancel',
                ],
            ]);
        } finally {
            $this->restoreChildCallProjectionWrites();
        }

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->assertSame('request_cancel', $link->parent_close_policy);

        $this->assertFalse(
            WorkflowChildCall::query()
                ->where('parent_workflow_run_id', $run->id)
                ->where('sequence', 1)
                ->exists(),
        );

        $this->assertTrue(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
                ->exists(),
        );
        $this->assertTrue(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ChildRunStarted->value)
                ->exists(),
        );

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame(RunStatus::Pending, $childRun->status);

        $this->assertTrue(
            WorkflowTask::query()
                ->where('workflow_run_id', $childRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->exists(),
        );
    }

    public function testChildCompletionAuthoritativeStateSurvivesChildCallProjectionUpdateFailure(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($childTask->id, 'external-child-worker');

        $this->failChildCallProjectionWrites('update');

        try {
            $completed = $this->bridge->complete($childTask->id, [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serialize(['child_result' => 'ok']),
                ],
            ]);
        } finally {
            $this->restoreChildCallProjectionWrites();
        }

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame(1, $childCompleted->payload['sequence'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $childCompleted->payload['child_workflow_run_id'] ?? null);

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSame('child', $parentResumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $parentResumeTask->payload['child_workflow_run_id'] ?? null);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Started, $childCall->status);
    }

    public function testParentClosePolicyAuthoritativeStateSurvivesChildCallProjectionUpdateFailure(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'parent_close_policy' => 'request_cancel',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $parentCloseTask = $this->createLeasedTask($run->fresh());

        $this->failChildCallProjectionWrites('update');

        try {
            $closed = $this->bridge->complete($parentCloseTask->id, [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serialize(['parent' => 'done']),
                ],
            ]);
        } finally {
            $this->restoreChildCallProjectionWrites();
        }

        $this->assertTrue($closed['completed']);
        $this->assertSame('completed', $closed['run_status']);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame(RunStatus::Cancelled, $childRun->status);

        /** @var WorkflowHistoryEvent $applied */
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->firstOrFail();

        $this->assertSame('request_cancel', $applied->payload['policy'] ?? null);
        $this->assertSame($link->child_workflow_instance_id, $applied->payload['child_instance_id'] ?? null);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Started, $childCall->status);
    }

    public function testCompleteStartsChildWorkflowSnapshotsRetryPolicyAndTimeouts(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [2, 8],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'execution_timeout_seconds' => 600,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame(120, $childRun->run_timeout_seconds);
        $this->assertNotNull($childRun->execution_deadline_at);
        $this->assertNotNull($childRun->run_deadline_at);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'max_attempts' => 3,
            'backoff_seconds' => [2, 8],
            'non_retryable_error_types' => ['ValidationError'],
        ], $childCall->retry_policy);
        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ], $childCall->timeout_policy);
        $this->assertSame($childRun->id, $childCall->resolved_child_run_id);

        /** @var WorkflowHistoryEvent $scheduledEvent */
        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->firstOrFail();

        $this->assertSame($childCall->retry_policy, $scheduledEvent->payload['retry_policy']);
        $this->assertSame($childCall->timeout_policy, $scheduledEvent->payload['timeout_policy']);

        /** @var WorkflowHistoryEvent $startedEvent */
        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame($childCall->retry_policy, $startedEvent->payload['retry_policy']);
        $this->assertSame(600, $startedEvent->payload['execution_timeout_seconds']);
        $this->assertSame(120, $startedEvent->payload['run_timeout_seconds']);
    }

    public function testChildWorkflowFailureStartsRetryAttemptBeforeParentResume(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $failed = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'retryable child failure',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($failed['completed']);
        $this->assertSame('failed', $failed['run_status']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $links);

        /** @var WorkflowLink $retryLink */
        $retryLink = $links->last();

        /** @var WorkflowRun $retryRun */
        $retryRun = WorkflowRun::query()->findOrFail($retryLink->child_workflow_run_id);

        $this->assertSame(2, $retryRun->run_number);
        $this->assertSame(RunStatus::Pending, $retryRun->status);
        $this->assertSame(120, $retryRun->run_timeout_seconds);

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $retryRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $retryTask->status);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame($retryRun->id, $childCall->resolved_child_run_id);
        $this->assertSame(2, $childCall->metadata['attempt_count'] ?? null);
        $this->assertSame(
            $initialLink->child_workflow_run_id,
            $childCall->metadata['last_retry_of_child_workflow_run_id'] ?? null
        );

        $childStarts = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $childStarts);
        /** @var WorkflowHistoryEvent $latestChildStart */
        $latestChildStart = $childStarts->last();

        $this->assertSame(2, $latestChildStart->payload['retry_attempt'] ?? null);
        $this->assertSame(
            $initialLink->child_workflow_run_id,
            $latestChildStart->payload['retry_of_child_workflow_run_id'] ?? null
        );

        $parentReadyTasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();

        $this->assertSame(0, $parentReadyTasks);
    }

    public function testChildWorkflowCompletionCreatesParentResumeTaskWithChildContext(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($childTask->id, 'external-child-worker');

        $completed = $this->bridge->complete($childTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'child_result' => 'ok',
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame(1, $childCompleted->payload['sequence'] ?? null);
        $this->assertSame($link->id, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $childCompleted->payload['child_workflow_run_id'] ?? null);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $this->assertSame(ChildCallStatus::Completed, $childCall->status);

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'workflow_wait_kind' => 'child',
            'open_wait_id' => sprintf('child:%s', $link->id),
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $link->child_workflow_run_id,
            'child_call_id' => $link->id,
            'child_workflow_run_id' => $link->child_workflow_run_id,
            'workflow_sequence' => 1,
            'workflow_event_type' => HistoryEventType::ChildRunCompleted->value,
        ], $parentResumeTask->payload);
    }

    public function testChildWorkflowStartPersistsWhenProjectionFailsAfterChildStateIsRecorded(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $this->bindThrowingHistoryProjectionRole();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertSame(TaskStatus::Completed->value, $task->refresh()->getRawOriginal('status'));

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->assertSame($run->id, $link->parent_workflow_run_id);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready->value, $childTask->getRawOriginal('status'));

        $this->assertTrue(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->exists());
        $this->assertTrue(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)
            ->exists());
    }

    public function testChildWorkflowCompletionPersistsParentResumeWhenChildProjectionFails(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($childTask->id, 'external-child-worker');
        $this->bindThrowingHistoryProjectionRole();

        $completed = $this->bridge->complete($childTask->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize([
                    'child_result' => 'ok',
                ]),
            ],
        ]);

        $this->assertTrue($completed['completed']);
        $this->assertSame('completed', $completed['run_status']);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame($childCompleted->payload['output'] ?? null, $childCompleted->payload['result'] ?? null);
        $this->assertSame(CodecRegistry::defaultCodec(), $childCompleted->payload['payload_codec'] ?? null);

        /** @var WorkflowTask $parentResumeTask */
        $parentResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'workflow_wait_kind' => 'child',
            'open_wait_id' => sprintf('child:%s', $link->id),
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $link->child_workflow_run_id,
            'child_call_id' => $link->id,
            'workflow_sequence' => 1,
            'workflow_event_type' => HistoryEventType::ChildRunCompleted->value,
        ], $parentResumeTask->payload);
    }

    public function testCompleteStartsChildWorkflowDefaultsToAbandonPolicy(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('abandon', $link->parent_close_policy);

        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->first();

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('abandon', $scheduledEvent->payload['parent_close_policy']);
    }

    public function testCompleteContinuesAsNew(): void
    {
        $run = $this->createWaitingRun();
        $now = now();
        $executionDeadline = $now->copy()->addMinutes(10);
        $run->forceFill([
            'run_timeout_seconds' => 90,
            'execution_deadline_at' => $executionDeadline,
            'run_deadline_at' => $now->copy()->addSeconds(15),
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['new-args']),
                'workflow_type' => 'next-workflow-type',
                'queue' => 'next-workers',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('continued', $run->closed_reason);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->first();

        $this->assertNotNull($link);

        $continuedRun = WorkflowRun::query()->find($link->child_workflow_run_id);
        $this->assertNotNull($continuedRun);
        $this->assertSame(RunStatus::Pending, $continuedRun->status);
        $this->assertSame($run->run_number + 1, $continuedRun->run_number);
        $this->assertSame($run->workflow_instance_id, $continuedRun->workflow_instance_id);
        $this->assertSame('next-workflow-type', $continuedRun->workflow_type);
        $this->assertSame('next-workers', $continuedRun->queue);
        $this->assertSame(90, $continuedRun->run_timeout_seconds);
        $this->assertTrue($continuedRun->execution_deadline_at->equalTo($executionDeadline));
        $this->assertTrue($continuedRun->run_deadline_at->greaterThan($run->run_deadline_at));

        $continuedTask = WorkflowTask::query()
            ->where('workflow_run_id', $continuedRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($continuedTask);
        $this->assertSame(TaskStatus::Ready, $continuedTask->status);

        $continuedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
            ->first();

        $this->assertNotNull($continuedEvent);
        $this->assertSame($continuedRun->id, $continuedEvent->payload['continued_to_run_id']);
    }

    public function testCompleteContinueAsNewIgnoresPayloadCodecWhenArgumentsAreInherited(): void
    {
        $run = $this->createWaitingRun();
        $inheritedArguments = Serializer::serializeWithCodec('workflow-serializer-y', ['Taylor']);
        $run->forceFill([
            'payload_codec' => 'workflow-serializer-y',
            'arguments' => $inheritedArguments,
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'payload_codec' => 'avro',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->firstOrFail();

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame('workflow-serializer-y', $continuedRun->payload_codec);
        $this->assertSame($inheritedArguments, $continuedRun->arguments);
        $this->assertSame(['Taylor'], $continuedRun->workflowArguments());
    }

    public function testCompleteContinueAsNewRejectsUnknownPayloadCodec(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['Avery']),
                'payload_codec' => 'not-a-codec',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);

        $run->refresh();
        $task->refresh();

        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame(0, WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->count());
    }

    public function testCompleteContinueAsNewUsesPayloadCodecWithReplacementArguments(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'payload_codec' => 'workflow-serializer-y',
            'arguments' => Serializer::serializeWithCodec('workflow-serializer-y', ['Taylor']),
        ])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);
        $replacementArguments = Serializer::serializeWithCodec('avro', ['Avery']);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => $replacementArguments,
                'payload_codec' => 'avro',
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->firstOrFail();

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame('avro', $continuedRun->payload_codec);
        $this->assertSame($replacementArguments, $continuedRun->arguments);
        $this->assertSame(['Avery'], $continuedRun->workflowArguments());
    }

    public function testCompleteContinueAsNewPreservesArgumentsEnvelopeCodec(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill(['payload_codec' => 'avro'])->save();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $codec = 'workflow-serializer-y';
        $replacementArguments = Serializer::serializeWithCodec($codec, ['Avery']);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => [
                    'codec' => $codec,
                    'blob' => $replacementArguments,
                ],
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->firstOrFail();

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame($codec, $continuedRun->payload_codec);
        $this->assertSame($replacementArguments, $continuedRun->arguments);
        $this->assertSame(['Avery'], $continuedRun->workflowArguments());
    }

    public function testCompleteWithMultipleNonTerminalCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-a',
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-b',
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('waiting', $result['run_status']);

        $activityExecutions = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->get();

        $this->assertCount(2, $activityExecutions);

        $activityTasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->get();

        $this->assertCount(2, $activityTasks);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer->value)
            ->first();

        $this->assertNotNull($timerTask);
    }

    public function testCompleteWithNonTerminalAndTerminalCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'fire-and-forget-activity',
            ],
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('done'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $result['run_status']);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertNotNull($execution);
    }

    public function testCompleteRejectsOnlyUnrecognizedCommands(): void
    {
        $result = $this->bridge->complete('any-task', [
            [
                'type' => 'unknown_command',
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame('invalid_commands', $result['reason']);
    }

    public function testScheduleActivityUsesRunDefaultsForConnectionAndQueue(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-activity',
            ],
        ]);

        $this->assertTrue($result['completed']);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->first();

        $this->assertSame($run->connection, $execution->connection);
        $this->assertSame($run->queue, $execution->queue);
    }

    public function testScheduleActivitySnapshotsExternalRetryPolicyAndTimeouts(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-activity',
                'retry_policy' => [
                    'max_attempts' => 4,
                    'backoff_seconds' => [1, 5, 30],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => 10,
                'schedule_to_close_timeout' => 300,
                'heartbeat_timeout' => 15,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSameJsonObject([
            'snapshot_version' => 1,
            'max_attempts' => 4,
            'backoff_seconds' => [1, 5, 30],
            'start_to_close_timeout' => 120,
            'schedule_to_start_timeout' => 10,
            'schedule_to_close_timeout' => 300,
            'heartbeat_timeout' => 15,
            'non_retryable_error_types' => ['ValidationError'],
        ], $execution->retry_policy);
        $this->assertSame(120, $execution->activity_options['start_to_close_timeout']);
        $this->assertSame(10, $execution->activity_options['schedule_to_start_timeout']);
        $this->assertSame(300, $execution->activity_options['schedule_to_close_timeout']);
        $this->assertSame(15, $execution->activity_options['heartbeat_timeout']);
        $this->assertNotNull($execution->schedule_deadline_at);
        $this->assertNotNull($execution->schedule_to_close_deadline_at);

        /** @var WorkflowHistoryEvent $scheduled */
        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();

        $this->assertSame($execution->retry_policy, $scheduled->payload['activity']['retry_policy']);
    }

    public function testCompleteThenActivityPollReturnsSameTickTasks(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'test-greeting-activity',
                'arguments' => Serializer::serialize(['Taylor']),
                'queue' => 'default',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(1, $result['created_task_ids']);

        // Poll for activity tasks immediately — same-tick tasks must be visible.
        $activityBridge = $this->app->make(\Workflow\V2\Contracts\ActivityTaskBridge::class);
        $polled = $activityBridge->poll('redis', 'default');

        $this->assertNotEmpty($polled, 'Same-tick activity tasks must be visible to ActivityTaskBridge::poll().');

        $polledTaskIds = array_column($polled, 'task_id');
        $this->assertContains($result['created_task_ids'][0], $polledTaskIds);
    }

    public function testCompleteReturnsCreatedTaskIdsForMixedCommands(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-a',
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'activity-b',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertCount(3, $result['created_task_ids']);

        // Verify each created_task_id points to a real task.
        foreach ($result['created_task_ids'] as $createdId) {
            $this->assertNotNull(WorkflowTask::query()->find($createdId));
        }
    }

    public function testCompleteReturnsEmptyCreatedTaskIdsOnRejection(): void
    {
        $run = $this->createWaitingRun();

        $result = $this->bridge->complete('nonexistent-task', [
            [
                'type' => 'complete_workflow',
                'result' => null,
            ],
        ]);

        $this->assertFalse($result['completed']);
        $this->assertSame([], $result['created_task_ids']);
    }

    public function testExecuteClosedRunUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'closed_at' => now(),
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->execute($task->id);

        $this->assertTrue($result['executed']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertSame([['projectRun', $run->id], ['projectRun', $run->id]], $customRole->calls);
    }

    public function testFailUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->fail($task->id, 'Worker crashed');

        $this->assertTrue($result['recorded']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCompleteWorkflowCompletionUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('Hello, Taylor'),
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCompleteWorkflowFailureUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'workflow failed',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testCompleteOpenConditionWaitUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'order-ready',
            ],
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testStartChildWorkflowUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->assertSame(
            [['projectRun', $link->child_workflow_run_id], ['projectRun', $run->id]],
            $customRole->calls,
        );
    }

    public function testChildWorkflowRetryUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['child-arg']),
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $initialLink */
        $initialLink = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowTask $initialChildTask */
        $initialChildTask = WorkflowTask::query()
            ->where('workflow_run_id', $initialLink->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->bridge->claimStatus($initialChildTask->id, 'external-child-worker');

        $customRole = $this->bindHistoryProjectionSpy();

        $failed = $this->bridge->complete($initialChildTask->id, [
            [
                'type' => 'fail_workflow',
                'message' => 'retryable child failure',
                'exception_class' => RuntimeException::class,
            ],
        ]);

        $this->assertTrue($failed['completed']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->get();

        /** @var WorkflowLink|null $retryLink */
        $retryLink = $links->last();

        $this->assertCount(2, $links);
        $this->assertNotNull($retryLink);
        $this->assertContains(['projectRun', $retryLink->child_workflow_run_id], $customRole->calls);
    }

    public function testCompleteContinueAsNewUsesHistoryProjectionRoleBinding(): void
    {
        $run = $this->createWaitingRun();

        /** @var WorkflowTask $task */
        $task = $this->createLeasedTask($run);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($task->id, [
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['new-args']),
            ],
        ]);

        $this->assertTrue($result['completed']);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'continue_as_new')
            ->firstOrFail();

        $this->assertSame(
            [['projectRun', $run->id], ['projectRun', $link->child_workflow_run_id]],
            $customRole->calls,
        );
    }

    private function bindHistoryProjectionSpy()
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                $this->calls[] = ['recordActivityStarted', $run->id, $execution->id, $attempt->id, $task->id];

                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        return $customRole;
    }

    private function bindThrowingHistoryProjectionRole(): void
    {
        $this->app->instance(HistoryProjectionRole::class, new class implements HistoryProjectionRole {
            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                throw new RuntimeException('projection unavailable');
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                throw new RuntimeException('projection unavailable');
            }
        });
    }

    private function bindExternalPayloadPolicy(ExternalPayloadStorageDriver $driver): void
    {
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
                ) {
                }

                public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
                {
                    return $this->driver;
                }

                public function thresholdBytesFor(?string $namespace): ?int
                {
                    return 1;
                }
            },
        );
    }

    private function bindNamespacedExternalPayloadPolicy(
        ExternalPayloadStorageDriver $driver,
        string $namespace,
    ): NamespacedExternalPayloadStoragePolicy
    {
        $policy = new NamespacedExternalPayloadStoragePolicy($driver, $namespace);

        $this->app->instance(ExternalPayloadStoragePolicy::class, $policy);

        return $policy;
    }

    private function failChildCallProjectionWrites(string ...$operations): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger coverage is required for transaction-abort semantics.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_workflow_child_call_projection_write()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'workflow_child_calls projection write disabled for test';
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach ($operations as $operation) {
            $operation = strtolower($operation);

            $this->assertContains($operation, ['insert', 'update']);

            DB::unprepared(sprintf(
                'CREATE TRIGGER fail_workflow_child_call_projection_%s BEFORE %s ON workflow_child_calls FOR EACH ROW EXECUTE FUNCTION fail_workflow_child_call_projection_write();',
                $operation,
                strtoupper($operation),
            ));
        }
    }

    private function restoreChildCallProjectionWrites(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS fail_workflow_child_call_projection_insert ON workflow_child_calls;');
        DB::unprepared('DROP TRIGGER IF EXISTS fail_workflow_child_call_projection_update ON workflow_child_calls;');
        DB::unprepared('DROP FUNCTION IF EXISTS fail_workflow_child_call_projection_write();');
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

    private function recordReceivedSignal(
        WorkflowRun $run,
        string $name = 'advance',
        string $waitId = 'signal-wait-race',
    ): WorkflowSignal {
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        $signalCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => $name,
                'arguments' => ['Ada'],
            ]),
            'accepted_at' => now(),
        ]);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()->create([
            'workflow_command_id' => $signalCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => $name,
            'signal_wait_id' => $waitId,
            'status' => 'received',
            'outcome' => 'signal_received',
            'command_sequence' => $signalCommand->command_sequence,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', ['Ada']),
            'received_at' => now(),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, [
            'workflow_command_id' => $signalCommand->id,
            'signal_id' => $signal->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'signal_name' => $name,
            'signal_wait_id' => $waitId,
        ], null, $signalCommand);

        return $signal;
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

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir().'/dw-workflow-task-bridge-'.bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function createSearchAttribute(WorkflowRun $run, string $key, mixed $value): void
    {
        $attribute = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
        ]);
        $attribute->setTypedValueWithInference($value);
        $attribute->upserted_at_sequence = 0;
        $attribute->inherited_from_parent = false;
        $attribute->save();
    }
}

final class NamespacedExternalPayloadStoragePolicy implements ExternalPayloadStoragePolicy
{
    /**
     * @var list<string|null>
     */
    public array $driverNamespaces = [];

    public function __construct(
        private readonly ExternalPayloadStorageDriver $driver,
        private readonly string $namespace,
    ) {
    }

    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        $this->driverNamespaces[] = $namespace;

        return $namespace === $this->namespace ? $this->driver : null;
    }

    public function thresholdBytesFor(?string $namespace): ?int
    {
        return $namespace === $this->namespace ? 1 : null;
    }
}

final class BridgeHistorySequenceWorkflow extends V2Workflow
{
    public function handle(string $input): mixed
    {
        $first = V2Workflow::activity('demo.first', $input);

        return V2Workflow::activity('demo.second', $first);
    }
}

#[Type('bridge-protocol-signal-replay-workflow')]
#[Signal('increment')]
final class BridgeProtocolSignalReplayWorkflow extends V2Workflow
{
    private static int $lastCount = 0;

    private int $count = 0;

    public static function reset(): void
    {
        self::$lastCount = 0;
    }

    public static function lastCount(): int
    {
        return self::$lastCount;
    }

    public function handle(): mixed
    {
        while (true) {
            $this->count += (int) V2Workflow::awaitSignal('increment');
            self::$lastCount = $this->count;
        }
    }
}
