<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestControlPlaneRoutingWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\RuntimeSignalControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowControlPlaneRoutingContractTest extends TestCase
{
    private WorkflowControlPlane $controlPlane;

    private RuntimeSignalControlPlane $runtimeControlPlane;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T07:10:00Z');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.types.workflows', [
                'control-routing' => TestControlPlaneRoutingWorkflow::class,
            ]);
        Queue::fake();
        WorkflowStub::fake();
        $this->controlPlane = app(WorkflowControlPlane::class);
        $this->runtimeControlPlane = app(RuntimeSignalControlPlane::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('operations')]
    public function testInvalidConfiguredTypeRefusesEveryMutationWithoutChangingRecords(string $operation): void
    {
        $source = $this->waitingSource();
        config()
            ->set('workflows.v2.types.workflows.control-routing', []);
        $this->assertSame([
            'workflow_instance_id' => $source->workflow_instance_id,
            'workflow_id' => $source->workflow_instance_id,
            'run_id' => $source->id,
            'workflow_type' => 'control-routing',
            'blocked_reason' => 'configured_workflow_type_invalid',
            'reason' => 'configured_workflow_type_invalid',
            'message' => 'Configured durable workflow type [control-routing] points to [array], which is not a loadable workflow class.',
            'status' => 409,
        ], $this->readOnly(fn (): array => $this->mutate($operation, $source->workflow_instance_id, [
            'namespace' => 'tenant-routing',
            'strict_configured_type_validation' => true,
        ])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        foreach (['signal', 'runtime-signal', 'update', 'cancel', 'terminate', 'repair', 'archive'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('missingTargets')]
    public function testMissingOrForeignNamespaceTargetRefusesMutation(string $operation, bool $foreign): void
    {
        $this->waitingSource();
        $instanceId = $foreign ? 'routing-source' : 'absent-routing';
        $this->assertSame([
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_id' => $instanceId,
            $operation === 'update' ? 'update_id' : 'workflow_command_id' => null,
            'reason' => 'instance_not_found',
            'status' => 404,
        ], $this->readOnly(fn (): array => $this->mutate($operation, $instanceId, [
            'namespace' => $foreign ? 'another-tenant' : 'tenant-routing',
        ])));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function missingTargets(): iterable
    {
        foreach (self::operations() as [$operation]) {
            yield $operation . ' missing' => [$operation, false];
            yield $operation . ' foreign' => [$operation, true];
        }
    }

    #[DataProvider('routes')]
    public function testClassRoutingDefaultsAndPerStartOverridesArePersisted(
        array $options,
        string $connection,
        string $queue
    ): void {
        $source = $this->waitingSource($options);
        $this->assertSame($connection, $source->connection);
        $this->assertSame($queue, $source->queue);
        $this->assertNotEmpty($source->tasks);
        foreach ($source->tasks as $task) {
            $this->assertSame($connection, $task->connection);
            $this->assertSame($queue, $task->queue);
        }
        $description = $this->readOnly(fn (): array => $this->controlPlane->describe(
            $source->workflow_instance_id,
            [
                'namespace' => 'tenant-routing',
            ],
        ));
        $this->assertSame($connection, $description['run']['connection']);
        $this->assertSame($queue, $description['run']['queue']);
        $query = $this->readOnly(fn (): array => $this->controlPlane->query(
            $source->workflow_instance_id,
            'currentStage',
            [
                'namespace' => 'tenant-routing',
                'strict_configured_type_validation' => true,
            ],
        ));
        $this->assertTrue($query['success']);
        $this->assertSame('waiting-for-finish', $query['result']);
        $this->assertSame('routing-stage', $query['query_name']);
        $this->assertSame($source->id, $query['run_id']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function routes(): iterable
    {
        yield 'class defaults' => [[], 'redis', 'routed-workflows'];
        yield 'connection override' => [[
            'connection' => 'custom-transport',
        ], 'custom-transport', 'routed-workflows'];
        yield 'queue override' => [[
            'queue' => 'priority-workflows',
        ], 'redis', 'priority-workflows'];
        yield 'both overrides' => [[
            'connection' => 'custom-transport',
            'queue' => 'priority-workflows',
        ], 'custom-transport', 'priority-workflows'];
    }

    public function testFullyQualifiedWorkflowTypePassesStrictValidation(): void
    {
        $start = $this->controlPlane->start(TestControlPlaneRoutingWorkflow::class, 'routing-class-source', [
            'namespace' => 'tenant-routing',
        ]);
        $this->assertTrue($start['started']);
        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(TestControlPlaneRoutingWorkflow::class, $source->workflow_type);
        $result = $this->readOnly(fn (): array => $this->controlPlane->query(
            $source->workflow_instance_id,
            'routing-stage',
            [
                'namespace' => 'tenant-routing',
                'strict_configured_type_validation' => true,
            ],
        ));
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['reason']);
        $this->assertSame('waiting-for-finish', $result['result']);
        $this->assertSame($source->id, $result['run_id']);
    }

    public function testUnstartedReservationUsesTheNewConfiguredClass(): void
    {
        config()->set('workflows.v2.types.workflows.control-routing', TestQueryWorkflow::class);
        $reserved = WorkflowStub::make(TestQueryWorkflow::class, 'routing-reserved', 'tenant-routing');
        $instance = WorkflowInstance::query()->findOrFail($reserved->id());
        $this->assertSame(TestQueryWorkflow::class, $instance->workflow_class);
        $this->assertNull($instance->current_run_id);
        $this->assertSame(0, $instance->run_count);
        $this->assertSame(0, WorkflowRun::query()->count());
        config()
            ->set('workflows.v2.types.workflows.control-routing', TestControlPlaneRoutingWorkflow::class);

        $start = $this->controlPlane->start('control-routing', $instance->id, [
            'namespace' => 'tenant-routing',
        ]);
        $this->assertTrue($start['started']);
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(TestControlPlaneRoutingWorkflow::class, $run->workflow_class);
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(TestControlPlaneRoutingWorkflow::class, $instance->fresh()->workflow_class);
        $this->assertSame('control-routing', $instance->fresh()->workflow_type);
        $this->assertSame($run->id, $instance->fresh()->current_run_id);
        $this->assertSame(1, $instance->fresh()->run_count);
        Queue::assertNothingPushed();
    }

    #[DataProvider('reservationConflicts')]
    public function testStartCannotReuseAnIdentityAcrossNamespacesOrTypes(bool $foreignNamespace): void
    {
        $source = $this->waitingSource();
        $before = $this->records();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->controlPlane->start(
                    $foreignNamespace ? 'control-routing' : 'different-durable-type',
                    $source->workflow_instance_id,
                    [
                        'namespace' => $foreignNamespace ? 'another-tenant' : 'tenant-routing',
                    ],
                );
                $this->fail('A conflicting reserved identity must be refused.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    $foreignNamespace
                    ? 'Workflow instance [routing-source] cannot be reused with a different namespace.'
                    : 'Workflow instance [routing-source] is reserved for durable type [control-routing] and cannot be reused for [different-durable-type].',
                    $exception->getMessage(),
                );
            }
        }
        $this->assertSame($before, $this->records());
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function reservationConflicts(): iterable
    {
        yield 'namespace conflict' => [true];
        yield 'type conflict' => [false];
    }

    #[DataProvider('describedRuns')]
    public function testDescribePreservesRedriveLineageTimeoutsAndHistoricalSelection(string $selection): void
    {
        [$source, $successor] = $this->redrivenRuns();
        $historical = $selection === 'source';
        $selected = $historical ? $source : $successor;
        $options = [
            'namespace' => 'tenant-routing',
        ];
        if ($selection !== 'implicit current') {
            $options['run_id'] = $selected->id;
        }
        $result = $this->readOnly(fn (): array => $this->controlPlane->describe(
            $source->workflow_instance_id,
            $options,
        ));
        $this->assertTrue($result['found']);
        $this->assertNull($result['reason']);
        $this->assertSame($source->workflow_instance_id, $result['workflow_instance_id']);
        $this->assertSame('tenant-routing', $result['namespace']);
        $this->assertSame('order-42', $result['business_key']);
        $this->assertSame(2, $result['run_count']);
        $this->assertSame(120, $result['execution_timeout_seconds']);
        $run = $result['run'];
        $this->assertSame($selected->id, $run['workflow_run_id']);
        $this->assertSame($historical ? 1 : 2, $run['run_number']);
        $this->assertSame(! $historical, $run['is_current_run']);
        $this->assertSame($historical ? 'failed' : 'completed', $run['status']);
        $this->assertSame($historical ? 'failed' : 'completed', $run['status_bucket']);
        $this->assertSame($historical ? null : $source->id, $run['continued_from_run_id']);
        $this->assertSame($historical ? null : 2, $run['resume_step_sequence']);
        $this->assertSame($historical ? null : 'redrive', $run['recovery_kind']);
        $this->assertSame(60, $run['run_timeout_seconds']);
        $this->assertSame('2026-10-08T07:12:00+00:00', $run['execution_deadline_at']);
        $this->assertSame('2026-10-08T07:11:00+00:00', $run['run_deadline_at']);
        $this->assertSame([
            'can_signal' => false,
            'can_query' => false,
            'can_update' => false,
            'can_cancel' => false,
            'can_terminate' => false,
            'can_repair' => false,
            'can_archive' => true,
        ], $result['actions']);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function describedRuns(): iterable
    {
        yield 'implicit current' => ['implicit current'];
        yield 'explicit current' => ['current'];
        yield 'historical source' => ['source'];
    }

    #[DataProvider('unavailableDescriptions')]
    public function testDescribeDoesNotLeakUnavailableSelectedRuns(string $case): void
    {
        [$source, $successor] = $this->redrivenRuns();
        $requested = 'absent-run';
        if ($case === 'foreign run') {
            $foreign = $this->waitingSource(instanceId: 'foreign-routing');
            $requested = $foreign->id;
        }
        $hidden = $case === 'foreign namespace';
        $result = $this->readOnly(fn (): array => $this->controlPlane->describe(
            $source->workflow_instance_id,
            [
                'namespace' => $hidden ? 'another-tenant' : 'tenant-routing',
                'run_id' => $hidden ? $successor->id : $requested,
            ],
        ));
        $this->assertSame(! $hidden, $result['found']);
        $this->assertSame($source->workflow_instance_id, $result['workflow_instance_id']);
        $this->assertSame($hidden ? 'instance_not_found' : 'run_not_found', $result['reason']);
        $this->assertNull($result['run']);
        $this->assertSame($hidden ? null : 'order-42', $result['business_key']);
        $this->assertSame($hidden ? 0 : 2, $result['run_count']);
        foreach ($result['actions'] as $allowed) {
            $this->assertFalse($allowed);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableDescriptions(): iterable
    {
        yield 'missing run' => ['missing run'];
        yield 'foreign run' => ['foreign run'];
        yield 'foreign namespace' => ['foreign namespace'];
    }

    #[DataProvider('missingStartedMetadata')]
    public function testDescribeRemainsReadOnlyWhenRecordedStartMetadataIsMissing(bool $removeEvent): void
    {
        $source = $this->waitingSource();
        $event = $source->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();
        if ($removeEvent) {
            $event->delete();
        } else {
            $event->forceFill([
                'payload' => null,
            ])->save();
        }
        $result = $this->readOnly(fn (): array => $this->controlPlane->describe(
            $source->workflow_instance_id,
            [
                'namespace' => 'tenant-routing',
            ],
        ));
        $this->assertTrue($result['found']);
        $this->assertSame($source->id, $result['run']['workflow_run_id']);
        $this->assertSame('waiting', $result['run']['status']);
        $this->assertTrue($result['run']['is_current_run']);
        foreach (['continued_from_run_id', 'resume_step_sequence', 'recovery_kind'] as $field) {
            $this->assertNull($result['run'][$field]);
        }
        $this->assertNull($result['reason']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function missingStartedMetadata(): iterable
    {
        yield 'missing event' => [true];
        yield 'null payload' => [false];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function waitingSource(array $options = [], string $instanceId = 'routing-source'): WorkflowRun
    {
        $start = $this->controlPlane->start('control-routing', $instanceId, $options + [
            'namespace' => 'tenant-routing',
        ]);
        $this->assertTrue($start['started']);
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Waiting, $run->status);

        return $run;
    }

    /**
     * @return array{WorkflowRun, WorkflowRun}
     */
    private function redrivenRuns(): array
    {
        $start = $this->controlPlane->start(TestRedriveWorkflow::class, 'routing-redrive', [
            'namespace' => 'tenant-routing',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'business_key' => 'order-42',
            'execution_timeout_seconds' => 120,
            'run_timeout_seconds' => 60,
        ]);
        $this->assertTrue($start['started']);
        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $source->status);
        $redrive = $this->controlPlane->redrive($source->workflow_instance_id, $source->id, [
            'namespace' => 'tenant-routing',
            'request_id' => 'routing-redrive-once',
        ]);
        $this->assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $successor = WorkflowRun::query()->findOrFail($redrive['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));

        return [$source->fresh(), $successor];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function mutate(string $operation, string $instanceId, array $options): array
    {
        return match ($operation) {
            'signal' => $this->controlPlane->signal($instanceId, 'finish', $options),
            'runtime-signal' => $this->runtimeControlPlane->runtimeSignal(
                $instanceId,
                WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL,
                $options,
            ),
            'update' => $this->controlPlane->update($instanceId, 'approve', $options),
            'cancel' => $this->controlPlane->cancel($instanceId, $options),
            'terminate' => $this->controlPlane->terminate($instanceId, $options),
            'repair' => $this->controlPlane->repair($instanceId, $options),
            'archive' => $this->controlPlane->archive($instanceId, $options),
        };
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function readOnly(callable $operation): array
    {
        $before = $this->records();
        $connection = (new WorkflowRun())->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $operation();
            $this->assertSameJsonObject($result, $operation());
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(insert|update|delete|replace|alter|create|drop)\b/i',
                $query['query'],
            );
        }
        $this->assertSame($before, $this->records());
        Queue::assertNothingPushed();

        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowTask::class, WorkflowLink::class,
            WorkflowMemo::class, WorkflowSearchAttribute::class, ActivityExecution::class,
            ActivityAttempt::class, WorkflowFailure::class, WorkflowSignal::class,
            WorkflowTimer::class, WorkflowUpdate::class, WorkflowRunSummary::class] as $model) {
            $records[$model] = $model::query()->orderBy((new $model())->getKeyName())->get()->map(
                static fn (Model $row): array => $row->getRawOriginal(),
            )->all();
        }

        return $records;
    }
}
