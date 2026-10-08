<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestControlPlaneRoutingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use function Workflow\V2\signal;
use Workflow\V2\SignalWithStartResult;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class WorkflowStubSignalWithStartMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 09:10:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'queue.connections.metadata-redis' => config('queue.connections.redis'),
                'workflows.v2.compatibility.current' => 'build-a',
                'workflows.v2.compatibility.supported' => ['build-a'],
                'workflows.v2.compatibility.namespace' => 'signal-start-metadata',
                'workflows.v2.types.workflows' => [
                    'signal-start-metadata' => SignalStartMetadataWorkflow::class,
                ],
                'workflows.v2.task_dispatch_mode' => 'queue',
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    #[DataProvider('newRuns')]
    public function testNewRunsPinMetadataRoutingAndSignalBeforeExecution(
        bool $completed,
        bool $strict,
        bool $override
    ): void {
        $workflow = $this->workflow();
        $previous = $completed ? $this->completePrevious($workflow) : null;
        $previousRecords = $previous === null ? null : $this->runRecords($previous->id);
        $options = StartOptions::returnExistingActive()
            ->withBusinessKey('new-business')
            ->withLabels([
                'group' => 'new',
                'revision' => 2,
            ])
            ->withMemo([
                'revision' => 2,
                'approved' => false,
                'nested' => [
                    'steps' => ['first', 'second'],
                ],
            ])
            ->withSearchAttributes([
                'region' => 'west',
                'attempts' => 2,
            ])
            ->withExecutionTimeout(120)
            ->withRunTimeout(60);
        $routing = $override ? new WorkflowOptions('metadata-redis', 'selected-start') : new WorkflowOptions();
        $result = $this->intake($workflow, $strict, 'pair', [
            'second' => 'Lee',
            'first' => 'Taylor',
        ], ['new-request', $routing, $options]);
        $run = WorkflowRun::query()->findOrFail($result->runId());
        $this->assertAccepted($result, true, 1);
        $this->assertSame($completed ? 2 : 1, $run->run_number);
        $this->assertSame(SignalStartMetadataWorkflow::class, $run->workflow_class);
        $this->assertSame('signal-start-metadata', $run->workflow_type);
        $this->assertSame('signal-start-metadata', $run->namespace);
        $this->assertSame('new-business', $run->business_key);
        $this->assertSameJsonObject([
            'group' => 'new',
            'revision' => '2',
        ], $run->visibility_labels);
        $this->assertSameJsonObject($options->memo, $run->typedMemos());
        $this->assertSameJsonObject($options->searchAttributes, $run->typedSearchAttributes());
        $this->assertSame(60, $run->run_timeout_seconds);
        $this->assertTrue($run->execution_deadline_at->equalTo(now()->addSeconds(120)));
        $this->assertTrue($run->run_deadline_at->equalTo(now()->addSeconds(60)));
        $this->assertSame($override ? 'metadata-redis' : 'redis', $run->connection);
        $this->assertSame($override ? 'selected-start' : 'signal-start-metadata', $run->queue);
        $this->assertSame(['new-request'], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
        $instance = WorkflowInstance::query()->findOrFail($workflow->id());
        $this->assertSame($run->id, $instance->current_run_id);
        $this->assertSame($run->run_number, $instance->run_count);
        $this->assertSame(120, $instance->execution_timeout_seconds);
        $this->assertSame('new-business', $instance->business_key);
        $this->assertSameJsonObject($options->memo, $instance->memo);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame(
            ['StartAccepted', 'WorkflowStarted', 'SignalReceived'],
            $events->map(static fn ($event): string => $event->event_type->value)
                ->all()
        );
        $started = $events[1]->payload;
        $this->assertSame(120, $started['execution_timeout_seconds']);
        $this->assertSame(60, $started['run_timeout_seconds']);
        $this->assertSame($run->execution_deadline_at->toIso8601String(), $started['execution_deadline_at']);
        $this->assertSame($run->run_deadline_at->toIso8601String(), $started['run_deadline_at']);
        $this->assertSameJsonObject($options->memo, $started['memo']);
        $this->assertSameJsonObject($options->searchAttributes, $started['search_attributes']);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame($run->connection, $task->connection);
        $this->assertSame($run->queue, $task->queue);
        $received = WorkflowSignal::query()->where('workflow_command_id', $result->commandId())->sole();
        $this->assertSame(SignalStatus::Received, $received->status);
        $this->assertSame(['Taylor', 'Lee'], $received->signalArguments());
        Queue::assertPushed(RunWorkflowTask::class, 1);
        $this->execute($workflow, [
            'request' => 'new-request',
            'signal' => ['Taylor', 'Lee'],
        ]);
        if ($previous !== null) {
            $this->assertSame($previousRecords, $this->runRecords($previous->id));
        }
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function newRuns(): iterable
    {
        foreach ([false, true] as $completed) {
            foreach ([false, true] as $strict) {
                foreach ([false, true] as $override) {
                    yield ($completed ? 'completed' : 'reserved') . ' ' . ($strict ? 'strict' : 'attempt') . ' ' . ($override ? 'override' : 'class defaults') => [
                        $completed,
                        $strict,
                        $override,
                    ];
                }
            }
        }
    }

    #[DataProvider('modes')]
    public function testNewLogicalExecutionInheritsVisibilityWithoutOldDeadlinesOrSearchIndexes(bool $strict): void
    {
        $workflow = $this->workflow();
        $previous = $this->completePrevious($workflow);
        $before = $this->runRecords($previous->id);
        $result = $this->intake($workflow, $strict, 'pair', ['Taylor', 'Lee'], ['next-request']);
        $this->assertAccepted($result, true, 1);
        $run = WorkflowRun::query()->findOrFail($result->runId());
        $this->assertSame(2, $run->run_number);
        $this->assertSame('old-business', $run->business_key);
        $this->assertSameJsonObject([
            'group' => 'old',
        ], $run->visibility_labels);
        $this->assertSameJsonObject([
            'revision' => 1,
        ], $run->typedMemos());
        $this->assertSame([], $run->typedSearchAttributes());
        $this->assertNull($run->execution_deadline_at);
        $this->assertNull($run->run_deadline_at);
        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull(WorkflowInstance::query()->findOrFail($workflow->id())->execution_timeout_seconds);
        $this->execute($workflow, [
            'request' => 'next-request',
            'signal' => ['Taylor', 'Lee'],
        ]);
        $this->assertSame($before, $this->runRecords($previous->id));
    }

    #[DataProvider('activeRuns')]
    public function testExistingActiveRunKeepsItsPinnedStartOptionsAndArguments(bool $strict, bool $named): void
    {
        $workflow = $this->workflow();
        $workflow->start('old-request', $this->previousOptions());
        $id = $workflow->runId();
        $before = WorkflowRun::query()->findOrFail($id);
        Queue::fake();
        Carbon::setTestNow('2026-10-08 09:11:00');
        $arguments = $named ? [
            'second' => 'Signal',
            'first' => 'Current',
        ] : ['Current', 'Signal'];
        $result = $this->intake($workflow, $strict, 'pair', $arguments, [
            'ignored-request', new WorkflowOptions('metadata-redis', 'ignored-queue'),
            StartOptions::returnExistingActive()->withBusinessKey('ignored-business')->withLabels([
                'group' => 'ignored',
            ])
                ->withMemo([
                    'revision' => 99,
                ])->withSearchAttributes([
                    'ignored' => true,
                ])
                ->withExecutionTimeout(1)
                ->withRunTimeout(1),
        ]);
        $this->assertAccepted($result, false, 2);
        $this->assertSame($id, $result->runId());
        $this->assertSame(1, WorkflowRun::query()->count());
        $run = $before->fresh();
        foreach ([
            'workflow_class',
            'workflow_type',
            'namespace',
            'business_key',
            'connection',
            'queue',
            'arguments',
            'started_at',
            'execution_deadline_at',
            'run_deadline_at',
            'run_timeout_seconds',
        ] as $field) {
            $this->assertSame($before->getRawOriginal($field), $run->getRawOriginal($field), $field);
        }
        $this->assertSameJsonObject([
            'group' => 'old',
        ], $run->visibility_labels);
        $this->assertSameJsonObject([
            'revision' => 1,
        ], $run->typedMemos());
        $this->assertSameJsonObject([
            'previous' => 'retained',
        ], $run->typedSearchAttributes());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $id)->count());
        $this->assertSame(
            ['ignored-request'],
            WorkflowCommand::query()->findOrFail($result->startCommandId())->payloadArguments()
        );
        Queue::assertNothingPushed();
        $this->execute($workflow, [
            'request' => 'old-request',
            'signal' => ['Current', 'Signal'],
        ]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function activeRuns(): iterable
    {
        foreach ([false, true] as $strict) {
            foreach ([false, true] as $named) {
                yield ($strict ? 'strict' : 'attempt') . ' ' . ($named ? 'named' : 'positional') => [$strict, $named];
            }
        }
    }

    #[DataProvider('reservedClasses')]
    public function testReservedClassResolutionPinsLoadableClassesAndRecoversRemovedClasses(
        bool $strict,
        bool $removed
    ): void {
        if ($removed) {
            // A prior deployment reserved the type before its recorded class was removed.
            WorkflowInstance::query()->create([
                'id' => 'signal-start-metadata-instance',
                'workflow_class' => 'Tests\\Fixtures\\RemovedSignalStartWorkflow',
                'workflow_type' => 'signal-start-metadata',
                'namespace' => 'signal-start-metadata',
                'reserved_at' => now(),
                'run_count' => 0,
            ]);
            $workflow = WorkflowStub::load('signal-start-metadata-instance', 'signal-start-metadata');
        } else {
            $workflow = $this->workflow();
        }
        config()
            ->set('workflows.v2.types.workflows.signal-start-metadata', TestControlPlaneRoutingWorkflow::class);
        $result = $this->intake(
            $workflow,
            $strict,
            $removed ? 'finish' : 'pair',
            $removed ? [] : ['Taylor', 'Lee'],
            []
        );
        $this->assertAccepted($result, true, 1);
        $run = WorkflowRun::query()->findOrFail($result->runId());
        $class = $removed ? TestControlPlaneRoutingWorkflow::class : SignalStartMetadataWorkflow::class;
        $this->assertSame($class, $run->workflow_class);
        $this->assertSame($class, WorkflowInstance::query()->findOrFail($workflow->id())->workflow_class);
        $this->assertSame('signal-start-metadata', $run->workflow_type);
        $this->assertSame($removed ? 'routed-workflows' : 'signal-start-metadata', $run->queue);
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
            'event_type',
            'WorkflowStarted'
        )->sole();
        $this->assertSame($class, $started->payload['workflow_class']);
        $this->assertEqualsCanonicalizing(
            $removed ? ['finish'] : ['pair', 'finish'],
            $started->payload['declared_signals']
        );
        $this->execute($workflow, $removed ? 'finished' : [
            'request' => 'default-request',
            'signal' => ['Taylor', 'Lee'],
        ]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function reservedClasses(): iterable
    {
        foreach ([false, true] as $strict) {
            yield ($strict ? 'strict' : 'attempt') . ' pins loadable class' => [$strict, false];
            yield ($strict ? 'strict' : 'attempt') . ' restores removed class' => [$strict, true];
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'attempt' => [false];
        yield 'strict' => [true];
    }

    private function workflow(): WorkflowStub
    {
        return WorkflowStub::make(
            SignalStartMetadataWorkflow::class,
            'signal-start-metadata-instance',
            'signal-start-metadata'
        );
    }

    /** @param array<int|string, mixed> $arguments
     * @param list<mixed> $start
     */
    private function intake(
        WorkflowStub $workflow,
        bool $strict,
        string $name,
        array $arguments,
        array $start
    ): SignalWithStartResult {
        return $strict ? $workflow->signalWithStart($name, $arguments, ...$start) : $workflow->attemptSignalWithStart(
            $name,
            $arguments,
            ...$start
        );
    }

    private function previousOptions(): StartOptions
    {
        return StartOptions::returnExistingActive()->withBusinessKey('old-business')->withLabels([
            'group' => 'old',
        ])
            ->withMemo([
                'revision' => 1,
            ])->withSearchAttributes([
                'previous' => 'retained',
            ])
            ->withExecutionTimeout(180)
            ->withRunTimeout(90);
    }

    private function completePrevious(WorkflowStub $workflow): WorkflowRun
    {
        $workflow->start('old-request', $this->previousOptions());
        $this->assertTrue($workflow->attemptSignalWithArguments('pair', ['Old', 'Signal'])->accepted());
        $this->execute($workflow, [
            'request' => 'old-request',
            'signal' => ['Old', 'Signal'],
        ]);
        $previous = WorkflowRun::query()->findOrFail($workflow->runId());
        Queue::fake();
        Carbon::setTestNow('2026-10-08 09:11:00');
        return $previous;
    }

    private function assertAccepted(SignalWithStartResult $result, bool $new, int $startSequence): void
    {
        $this->assertTrue($result->accepted());
        $this->assertFalse($result->rejected());
        $this->assertTrue($result->startAccepted());
        $this->assertSame($new, $result->startedNew());
        $this->assertSame(! $new, $result->returnedExistingActive());
        $this->assertSame($new ? 'started_new' : 'returned_existing_active', $result->startOutcome());
        $this->assertSame('accepted', $result->startStatus());
        $this->assertSame('signal_received', $result->outcome());
        $this->assertSame($startSequence, $result->startCommandSequence());
        $this->assertSame($startSequence + 1, $result->commandSequence());
        $this->assertNotNull($result->startResult());
        $this->assertSame($result->startCommandId(), $result->startResult()->commandId());
        $commands = WorkflowCommand::query()->whereIn('id', [$result->startCommandId(), $result->commandId()])->get();
        foreach ($commands as $command) {
            $this->assertSame($result->intakeGroupId(), $command->intakeGroupId());
            $this->assertSame('signal_with_start', $command->commandContext()['intake']['mode']);
        }
        $this->assertSame(2, $commands->count());
    }

    private function execute(WorkflowStub $workflow, mixed $expected): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->where(
            'status',
            TaskStatus::Ready->value
        )->sole();
        $result = $this->app->make(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertTrue($result['executed']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertNull($result['next_task_id']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($workflow->runId())->status);
        $this->assertSame('completed', WorkflowRunSummary::query()->findOrFail($workflow->runId())->status);
        $this->assertSame($expected, $workflow->refresh()->output());
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function runRecords(string $id): array
    {
        $records = [
            WorkflowRun::class => [WorkflowRun::query()->findOrFail($id)->getRawOriginal()],
        ];
        foreach ([
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
            WorkflowCommand::class,
            WorkflowSignal::class,
            WorkflowMemo::class,
            WorkflowSearchAttribute::class,
        ] as $model) {
            $records[$model] = $model::query()->where('workflow_run_id', $id)->orderBy(
                (new $model())->getKeyName()
            )->get()
                ->map(static fn ($row): array => $row->getRawOriginal())
                ->all();
        }
        $records[WorkflowRunSummary::class] = [WorkflowRunSummary::query()->findOrFail($id)->getRawOriginal()];
        return $records;
    }
}

#[Signal('pair', [[
    'name' => 'first',
    'type' => 'string',
], [
    'name' => 'second',
    'type' => 'string',
]])]
#[Signal('finish')]
final class SignalStartMetadataWorkflow extends Workflow
{
    public ?string $connection = 'redis';

    public ?string $queue = 'signal-start-metadata';

    public function handle(string $request = 'default-request'): array
    {
        return [
            'request' => $request,
            'signal' => signal('pair'),
        ];
    }
}
