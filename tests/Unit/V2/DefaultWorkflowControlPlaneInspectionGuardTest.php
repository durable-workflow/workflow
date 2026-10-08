<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Fixtures\V2\StringCastWorkflowHistoryEvent;
use Tests\Fixtures\V2\TestControlPlaneInspectionWorkflow;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
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
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\FailedRunRedrivePlan;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowControlPlaneInspectionGuardTest extends TestCase
{
    private WorkflowControlPlane $controlPlane;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T06:20:00Z');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.types.workflows', [
                'control-inspection' => TestControlPlaneInspectionWorkflow::class,
            ]);
        Queue::fake();
        WorkflowStub::fake();
        $this->controlPlane = app(WorkflowControlPlane::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('values')]
    public function testQueriesPreserveTypedResultsAndRecordedState(mixed $value): void
    {
        $source = $this->waitingSource();
        $signal = $this->controlPlane->signal($source->workflow_instance_id, 'value', [
            'namespace' => 'tenant-inspection',
            'arguments' => [$value],
        ]);
        $this->assertTrue($signal['accepted']);
        $this->assertSame(RunStatus::Waiting, $source->fresh()->status);

        foreach (['inspection-value', 'currentValue', 'required-value', 'requireValue'] as $name) {
            $result = $this->queryReadOnly($source, $name);
            $canonical = in_array($name, ['inspection-value', 'currentValue'], true)
                ? 'inspection-value' : 'required-value';
            $this->assertSameJsonObject($this->queryMetadata($source, $canonical) + [
                'success' => true,
                'reason' => null,
                'status' => 200,
            ], array_diff_key($result, array_flip(['result', 'result_envelope'])));
            $this->assertSameJsonObject([
                'value' => $value,
            ], [
                'value' => $result['result'],
            ]);
            if ($value === null) {
                $this->assertNull($result['result_envelope']);
            } else {
                $this->assertSame('avro', $result['result_envelope']['codec']);
                $this->assertSameJsonObject([
                    'value' => $value,
                ], [
                    'value' => Serializer::unserializeWithCodec('avro', $result['result_envelope']['blob']),
                ]);
            }
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'zero' => [0];
        yield 'negative integer' => [-1];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'mixed list' => [[0, false, '']];
        yield 'map' => [[
            'zero' => 0,
            'enabled' => false,
            'text' => '',
        ]];
        yield 'Unicode' => ['Bonjour, 世界'];
    }

    public function testMissingDefinitionHasStructuredExecutionDiagnostics(): void
    {
        $source = $this->waitingSource();
        $this->removeDefinition($source);
        $result = $this->queryReadOnly($source, 'inspection-value');
        $this->assertSameJsonObject($this->queryMetadata($source, 'inspection-value') + [
            'success' => false,
            'result' => null,
            'reason' => 'workflow_definition_unavailable',
            'blocked_reason' => 'workflow_definition_unavailable',
            'message' => sprintf(
                'Workflow %s [%s] cannot execute query [inspection-value] because the workflow definition is unavailable for durable type [missing-inspection].',
                $source->id,
                $source->workflow_instance_id,
            ),
            'status' => 409,
        ], $result);
    }

    #[DataProvider('invalidArguments')]
    public function testRecordedArgumentsAreValidatedBeforeMissingDefinition(
        array $arguments,
        array $errors
    ): void {
        $source = $this->waitingSource();
        $this->removeDefinition($source);
        $result = $this->queryReadOnly($source, 'contains-text', [
            'arguments' => $arguments,
        ]);
        $this->assertSameJsonObject($this->queryMetadata($source, 'contains-text') + [
            'success' => false,
            'result' => null,
            'reason' => 'invalid_query_arguments',
            'message' => 'Workflow query [contains-text] received invalid arguments.',
            'validation_errors' => $errors,
            'status' => 422,
        ], $result);
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>, array<string, list<string>>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'missing required argument' => [[], [
            'needle' => ['The needle argument is required.'],
        ]];
        yield 'unknown named argument' => [[
            'unexpected' => 'x',
        ], [
            'needle' => ['The needle argument is required.'],
            'unexpected' => ['Unknown argument [unexpected].'],
        ]];
        yield 'wrong argument type' => [[
            'needle' => [],
        ], [
            'needle' => ['The needle argument must be of type string.'],
        ]];
        yield 'too many positional arguments' => [['x', 'extra'], [
            'arguments' => ['Too many arguments were provided for query [contains-text].'],
        ]];
    }

    #[DataProvider('requiredQueryNames')]
    public function testQueryBusinessGuardReturnsConflictWithoutMutation(string $name): void
    {
        $source = $this->waitingSource();
        $this->assertSameJsonObject($this->queryMetadata($source, 'required-value') + [
            'success' => false,
            'result' => null,
            'reason' => 'query_rejected',
            'message' => 'A value has not been received.',
            'status' => 409,
        ], $this->queryReadOnly($source, $name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredQueryNames(): iterable
    {
        yield 'durable alias' => ['required-value'];
        yield 'method name' => ['requireValue'];
    }

    public function testConflictingRecordedWaitReturnsQueryConflict(): void
    {
        $source = $this->waitingSource();
        $source->historyEvents()
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail()
            ->forceFill([
                'event_type' => HistoryEventType::TimerScheduled,
            ])->save();
        $result = $this->queryReadOnly($source, 'currentValue');
        $this->assertSame('query_rejected', $result['reason']);
        $this->assertSame(409, $result['status']);
        $this->assertFalse($result['success']);
        $this->assertNull($result['result']);
        $this->assertSame($this->queryMetadata($source, 'inspection-value'), array_intersect_key(
            $result,
            $this->queryMetadata($source, 'inspection-value'),
        ));
        $this->assertStringContainsString('TimerScheduled', $result['message']);
        $this->assertStringContainsString('signal wait', $result['message']);
    }

    #[DataProvider('invalidConfiguredTypes')]
    public function testStrictConfiguredTypeValidationReportsInvalidTarget(mixed $target, string $label): void
    {
        $source = $this->waitingSource();
        config()
            ->set('workflows.v2.types.workflows.control-inspection', $target);
        $this->assertSame([
            'workflow_instance_id' => $source->workflow_instance_id,
            'workflow_id' => $source->workflow_instance_id,
            'run_id' => $source->id,
            'workflow_type' => 'control-inspection',
            'blocked_reason' => 'configured_workflow_type_invalid',
            'reason' => 'configured_workflow_type_invalid',
            'message' => sprintf(
                'Configured durable workflow type [control-inspection] points to [%s], which is not a loadable workflow class.',
                $label,
            ),
            'status' => 409,
        ], $this->queryReadOnly($source, 'currentValue', [
            'strict_configured_type_validation' => true,
        ]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidConfiguredTypes(): iterable
    {
        yield 'unrelated class' => [stdClass::class, 'stdClass'];
        yield 'absent class' => [
            'Tests\\Fixtures\\V2\\MissingInspectionWorkflow',
            'Tests\\Fixtures\\V2\\MissingInspectionWorkflow',
        ];
        yield 'array target' => [[], 'array'];
        yield 'object target' => [new stdClass(), 'stdClass'];
        yield 'null target' => [null, 'null'];
        yield 'boolean target' => [true, '1'];
    }

    #[DataProvider('unavailableInstances')]
    public function testMissingAndForeignNamespaceInstancesReturnNotFound(string $instanceId, string $namespace): void
    {
        $this->waitingSource();
        $this->assertSame([
            'success' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_id' => $instanceId,
            'result' => null,
            'reason' => 'instance_not_found',
            'status' => 404,
        ], $this->readOnly(fn (): array => $this->controlPlane->query($instanceId, 'currentValue', [
            'namespace' => $namespace,
        ])));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unavailableInstances(): iterable
    {
        yield 'missing instance' => ['absent-inspection', 'tenant-inspection'];
        yield 'foreign namespace' => ['inspection-source', 'another-tenant'];
    }

    public function testUnknownQueryReturnsNotFoundWithCurrentRunIdentity(): void
    {
        $source = $this->waitingSource();
        $this->assertSameJsonObject($this->queryMetadata($source, 'undeclared') + [
            'success' => false,
            'result' => null,
            'reason' => 'query_not_found',
            'message' => 'Workflow query [undeclared] is not declared on workflow [inspection-source].',
            'status' => 404,
        ], $this->queryReadOnly($source, 'undeclared'));
    }

    #[DataProvider('unsafeRedriveHistories')]
    public function testUnsafeRedriveHistoryIsRejectedWithoutWork(string $case, string $reason): void
    {
        $start = $this->controlPlane->start(TestRedriveWorkflow::class, 'redrive-inspection', [
            'namespace' => 'tenant-inspection',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
        ]);
        $this->assertTrue($start['started']);
        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $source->status);
        $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:second-calls'));

        if ($case === 'activity count gap') {
            $event = $source->historyEvents()
                ->where('event_type', HistoryEventType::ActivityFailed->value)
                ->firstOrFail();
            $event->forceFill([
                'payload' => array_merge($event->payload, [
                    'sequence' => 3,
                ]),
            ])->save();
            $this->assertSame('activity_sequence_gap', FailedRunRedrivePlan::forRun($source->fresh())['reason']);
        } elseif ($case === 'missing start payload') {
            $source->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->firstOrFail()
                ->forceFill([
                    'payload' => null,
                ])->save();
            $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        } else {
            config()->set('workflows.v2.history_event_model', StringCastWorkflowHistoryEvent::class);
            $this->assertInstanceOf(StringCastWorkflowHistoryEvent::class, $source->fresh()->historyEvents()->first());
            $this->assertSame('invalid_history', FailedRunRedrivePlan::forRun($source->fresh())['reason']);
        }

        $this->assertSame([
            'accepted' => false,
            'workflow_instance_id' => $source->workflow_instance_id,
            'workflow_run_id' => null,
            'continued_from_run_id' => $source->id,
            'resume_step_sequence' => null,
            'task_id' => null,
            'reason' => $reason,
            'status' => 409,
        ], $this->readOnly(fn (): array => $this->controlPlane->redrive(
            $source->workflow_instance_id,
            $source->id,
            [
                'namespace' => 'tenant-inspection',
                'request_id' => 'redrive-inspection-once',
            ],
        )));
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:second-calls'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeRedriveHistories(): iterable
    {
        yield 'activity count gap' => ['activity count gap', 'activity_sequence_gap'];
        yield 'missing start payload' => ['missing start payload', 'workflow_definition_unavailable_or_changed'];
        yield 'incompatible configured event casts' => ['string event type', 'invalid_history'];
    }

    private function waitingSource(): WorkflowRun
    {
        $start = $this->controlPlane->start('control-inspection', 'inspection-source', [
            'namespace' => 'tenant-inspection',
        ]);
        $this->assertTrue($start['started']);
        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Waiting, $source->status);

        return $source;
    }

    private function removeDefinition(WorkflowRun $source): void
    {
        $source->forceFill([
            'workflow_class' => 'Tests\\Fixtures\\V2\\MissingInspectionWorkflow',
            'workflow_type' => 'missing-inspection',
        ])->save();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function queryReadOnly(WorkflowRun $source, string $name, array $options = []): array
    {
        return $this->readOnly(fn (): array => $this->controlPlane->query($source->workflow_instance_id, $name, [
            'namespace' => 'tenant-inspection',
        ] + $options));
    }

    /**
     * @return array<string, string>
     */
    private function queryMetadata(WorkflowRun $source, string $name): array
    {
        return [
            'workflow_instance_id' => $source->workflow_instance_id,
            'workflow_id' => $source->workflow_instance_id,
            'run_id' => $source->id,
            'target_scope' => 'instance',
            'query_name' => $name,
        ];
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
            WorkflowTimer::class, WorkflowUpdate::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal(),
            )->all();
        }

        return $records;
    }
}
