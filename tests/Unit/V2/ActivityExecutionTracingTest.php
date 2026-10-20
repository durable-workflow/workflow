<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\V2\Activity;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\TaskOutcomeCarrier;
use Workflow\V2\Support\TraceCarrier;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ActivityExecutionTracingTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $provider;

    private ScopeInterface $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->configuration = Configurator::create()->withTracerProvider($this->provider)
            ->withPropagator(TraceContextPropagator::getInstance())->activate();
        TracedActivity::$observations = [];
        LocalTracedWorkflow::$scopes = [];
    }

    protected function tearDown(): void
    {
        self::assertSame([], ExecutionTracing::current());
        $this->configuration->detach();
        $this->provider->shutdown();
        parent::tearDown();
    }

    public static function modes(): array
    {
        return [
            'default disabled' => [false, false],
            'enabled legacy run' => [true, false],
            'enabled retry' => [true, true],
        ];
    }

    #[DataProvider('dispatchFailures')]
    public function testPreparedScheduleFilteringUsesBoundedSpanAttributesWithoutChangingBusinessWork(
        bool $enabled,
    ): void {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $propagator = Configurator::create()->withPropagator(new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(), BaggagePropagator::getInstance(),
        ]))->activate();
        $caller = $this->provider->getTracer('application')
            ->spanBuilder('request')
            ->startSpan();
        $scope = Baggage::getBuilder()->set('private', 'secret')->build()
            ->storeInContext($caller->storeInContext(Context::getCurrent()))->activate();
        $schedule = new WorkflowSchedule();
        $schedule->setRawAttributes([
            'schedule_id' => 'bounded-diagnostic',
            'namespace' => '0',
        ]);
        try {
            self::assertSame(7, ExecutionTracing::scheduleAdmission($schedule, static function () use ($enabled): int {
                $carrier = TraceCarrier::admissionParent();
                if ($enabled) {
                    self::assertNotNull($carrier);
                    self::assertArrayNotHasKey('baggage', $carrier['carrier']);
                } else {
                    self::assertNull($carrier);
                    ExecutionTracing::recordCarrierDiagnostic('discarded');
                }
                ExecutionTracing::recordCarrierDiagnostic('private=secret');

                return 7;
            }, 'scheduled'));
        } finally {
            $scope->detach();
            $caller->end();
            $propagator->detach();
        }
        $spans = $this->exporter->getSpans();
        self::assertCount($enabled ? 2 : 1, $spans);
        foreach ($spans as $span) {
            self::assertSame($enabled ? 'filtered' : null, $span->getAttributes()
                ->get('durable.trace_context.disposition'));
            self::assertSame([], $span->getEvents());
            self::assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            self::assertStringNotContainsString('secret', json_encode(
                $span->getAttributes()
                    ->toArray(),
                JSON_THROW_ON_ERROR
            ));
        }
        self::assertSame(0, WorkflowRun::query()->count());
        self::assertSame(0, DB::table('jobs')->count());
    }

    #[DataProvider('dispatchFailures')]
    public function testInvalidCapturedParentIsDiagnosedOnAdmissionWithoutAnApplicationSpan(bool $enabled): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $propagator = new class() implements TextMapPropagatorInterface {
            public int $injections = 0;

            public function fields(): array
            {
                return ['traceparent'];
            }

            public function inject(
                mixed &$carrier,
                ?PropagationSetterInterface $setter = null,
                ?ContextInterface $context = null,
            ): void {
                ++$this->injections;
                $carrier['traceparent'] = 'private-invalid-header';
            }

            public function extract(
                $carrier,
                ?PropagationGetterInterface $getter = null,
                ?ContextInterface $context = null,
            ): ContextInterface {
                return $context ?? Context::getCurrent();
            }
        };
        $scope = Configurator::create()->withPropagator($propagator)->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'invalid-admission-parent', '0');
            $stub->start(false);
            for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
                $this->workOnce();
            }
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            self::assertSame('completed', $run->status->value);
            self::assertSame([9007199254740993, null, '雪'], $run->workflowOutput());
            self::assertNull($run->observability_context);
            self::assertSame(0, DB::table('jobs')->count());
            self::assertSame(0, DB::table('failed_jobs')->count());
            if ($enabled) {
                self::assertGreaterThan(0, $propagator->injections);
            } else {
                self::assertSame(0, $propagator->injections);
            }
            $admissions = array_values(array_filter(
                $this->exporter->getSpans(),
                static fn ($span): bool => $span->getName() === 'durable.workflow.admission'
            ));
            self::assertCount($enabled ? 1 : 0, $admissions);
            if ($enabled) {
                self::assertSame('discarded', $admissions[0]->getAttributes()
                    ->get('durable.trace_context.disposition'));
                self::assertNotSame(StatusCode::STATUS_ERROR, $admissions[0]->getStatus()->getCode());
                self::assertStringNotContainsString('private-invalid-header', json_encode(
                    $admissions[0]->getAttributes()
                        ->toArray(),
                    JSON_THROW_ON_ERROR
                ));
            } else {
                self::assertSame([], $this->exporter->getSpans());
            }
        } finally {
            $scope->detach();
        }
    }

    public static function nonQueueModes(): array
    {
        return [
            'poll bookkeeping' => ['poll'],
            'fake execution' => ['fake'],
        ];
    }

    #[DataProvider('nonQueueModes')]
    public function testNonQueuePathsDoNotClaimQueueProducerSpans(string $mode): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        if ($mode === 'poll') {
            config([
                'workflows.v2.task_dispatch_mode' => 'poll',
            ]);
        } else {
            WorkflowStub::fake();
        }
        $stub = WorkflowStub::make(TracedWorkflow::class, 'non-queue-' . $mode, '0');
        $stub->start(false);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame([], array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.task.dispatch'
        )));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertSame($mode === 'poll' ? 'pending' : 'completed', $run->status->value);
        if ($mode === 'fake') {
            self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        }
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public static function dispatchFailures(): array
    {
        return [
            'disabled' => [false],
            'enabled' => [true],
        ];
    }

    #[DataProvider('dispatchFailures')]
    public function testRealQueueStorageFailureRetainsTheOriginalAdmissionAndFailure(bool $enabled): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
            'queue.connections.database.table' => 'missing_trace_jobs',
        ]);
        $stub = WorkflowStub::make(TracedWorkflow::class, 'queue-storage-failure', '0');
        try {
            $stub->start(false, new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
            self::fail('The real queue INSERT must fail.');
        } catch (QueryException $failure) {
            self::assertStringContainsString('missing_trace_jobs', $failure->getMessage());
        }
        $run = WorkflowRun::query()->where('workflow_instance_id', 'queue-storage-failure')->sole();
        self::assertSame('pending', $run->status->value);
        self::assertSame([false], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
        self::assertTrue($run->started_at->copy()->addSeconds(60)->equalTo($run->execution_deadline_at));
        self::assertSame(45, $run->run_timeout_seconds);
        self::assertTrue($run->started_at->copy()->addSeconds(45)->equalTo($run->run_deadline_at));
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        self::assertSame('ready', $task->status->value);
        self::assertNull($task->last_dispatched_at);
        self::assertStringContainsString('missing_trace_jobs', $task->last_dispatch_error);
        self::assertSame(0, (int) $task->repair_count);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, ActivityAttempt::query()->count());
        self::assertSame('started_new', WorkflowCommand::query()->sole()->outcome->value);
        $spans = array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.task.dispatch'
        ));
        self::assertCount($enabled ? 1 : 0, $spans);
        foreach ($spans as $span) {
            self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            self::assertSame(QueryException::class, $span->getAttributes()->get('error.type'));
            self::assertNull($span->getAttributes()->get('durable.dispatch.outcome'));
            self::assertSame([], $span->getEvents());
            self::assertStringNotContainsString(
                'missing_trace_jobs',
                json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testAdmissionPersistsItsOriginalParentAndDuplicateCannotReplaceIt(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $parent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => $parent,
        ])->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'captured-original', '0');
            $stub->start(false, new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
        } finally {
            $scope->detach();
        }
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertSame($parent, $run->observability_context['carrier']['traceparent']);
        self::assertSame([false], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
        $original = $run->getRawOriginal('observability_context');
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-11111111111111111111111111111111-2222222222222222-01',
        ])->activate();
        try {
            self::assertTrue(
                $stub->attemptStart(false, StartOptions::returnExistingActive())->returnedExistingActive()
            );
            $run->refresh();
            self::assertSame($original, $run->getRawOriginal('observability_context'));
            self::assertSame(
                $deadlines,
                [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')]
            );
            for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
                $this->workOnce();
                self::assertSame([], ExecutionTracing::current());
            }
        } finally {
            $scope->detach();
        }
        $stub->refresh();
        self::assertSame([9007199254740993, null, '雪'], $stub->output());
        $spans = $this->activitySpans();
        self::assertCount(1, $spans);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $spans[0]->getContext()->getTraceId());
        self::assertSame('00f067aa0ba902b7', $spans[0]->getParentContext()->getSpanId());
        self::assertStringNotContainsString('observability_context', json_encode(
            WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->pluck('payload')->all(),
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    public function testCompletedChildOutcomeIsReadFromCommittedRowsAndNeverChangesDurableState(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $stub = WorkflowStub::make(ParentTracedWorkflow::class, 'child-outcome-readback', '0');
        $stub->start();
        for ($remaining = 12; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
        }
        $parent = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertSame('completed', $parent->status->value);
        self::assertSame([9007199254740993, null, '雪'], $parent->workflowOutput());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->sole();
        $child = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        self::assertSame('completed', $child->status->value);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $child->id)
            ->where('event_type', 'WorkflowCompleted')
            ->sole();
        $childTask = WorkflowTask::query()->findOrFail($terminal->workflow_task_id);
        $claim = WorkflowTask::query()->where('workflow_run_id', $parent->id)->latest('id')->firstOrFail();
        $entry = TaskOutcomeCarrier::fromTask($childTask)['outcomes'][0];
        $before = [];
        foreach ([
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
            WorkflowLink::class,
        ] as $model) {
            $before[$model] = $model::query()->orderBy('id')->get()->map(
                static fn ($row): array => $row->getRawOriginal(),
            )->all();
        }
        $outcomes = TaskOutcomeCarrier::forRun($parent, $claim);
        self::assertSame($entry + [
            'task_id' => $childTask->id,
            'child_run_id' => $child->id,
            'child_instance_id' => $child->workflow_instance_id,
            'child_namespace' => $child->namespace,
            'parent_run_id' => $parent->id,
            'parent_namespace' => $parent->namespace,
            'parent_sequence' => $link->sequence,
            'link_id' => $link->id,
        ], $outcomes['child:' . $child->id]);
        self::assertSame($terminal->id, $entry['event_id']);
        foreach ($before as $model => $rows) {
            self::assertSame($rows, $model::query()->orderBy('id')->get()->map(
                static fn ($row): array => $row->getRawOriginal(),
            )->all());
        }

        // A valid carrier bound to a different physical event is not evidence of this child's outcome.
        $wrong = TaskOutcomeCarrier::fromTask($childTask);
        $wrong['outcomes'][0]['sequence'] = (int) $terminal->sequence + 1;
        DB::table($childTask->getTable())->where('id', $childTask->id)->update([
            'observability_context' => json_encode($wrong, JSON_THROW_ON_ERROR),
        ]);
        $withoutChild = TaskOutcomeCarrier::forRun($parent, $claim);
        self::assertArrayNotHasKey('child:' . $child->id, $withoutChild);
        self::assertArrayHasKey(
            $parent->historyEvents()
                ->where('event_type', 'WorkflowCompleted')
                ->sole()
->id,
            $withoutChild
        );
        self::assertSame([9007199254740993, null, '雪'], $parent->fresh()->workflowOutput());
        self::assertSame([9007199254740993, null, '雪'], $child->fresh()->workflowOutput());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public function testOutcomeMetadataRollsBackWithItsEventAndDuplicateCannotReplaceIt(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $stub = WorkflowStub::make(TracedWorkflow::class, 'outcome-rollback', '0');
        $stub->start(false);
        $this->workOnce();
        $task = WorkflowTask::query()->where('task_type', 'activity')->sole();
        [$claim] = ActivityTaskClaimer::claim($task->id, $task->id);
        self::assertNotNull($claim);
        $prefix = WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map(
            static fn ($event): array => $event->getRawOriginal(),
        )->all();
        $failure = new RuntimeException('deliberate outer outcome rollback');
        $record = static fn (): array => ActivityOutcomeRecorder::record(
            $claim->task->id,
            $claim->attemptId(),
            $claim->attemptNumber(),
            [9007199254740993, null, '雪'],
            null,
            2,
            0,
        );
        ExecutionTracing::activity([
            'workflow_instance_id' => $claim->run->workflow_instance_id,
            'workflow_run_id' => $claim->run->id,
            'workflow_type' => $claim->run->workflow_type,
            'namespace' => $claim->run->namespace,
            'task_id' => $claim->task->id,
            'activity_id' => $claim->execution->id,
            'activity_attempt_id' => $claim->attemptId(),
            'activity_attempt_number' => $claim->attemptNumber(),
        ], null, static function () use ($claim, $record, $failure, $prefix): void {
            try {
                $claim->task->getConnection()
                    ->transaction(static function () use ($record, $failure): void {
                        self::assertTrue($record()['recorded']);
                        throw $failure;
                    });
                self::fail('Expected the constructed outer rollback.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame($prefix, WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map(
                static fn ($event): array => $event->getRawOriginal(),
            )->all());
            $fresh = $claim->task->fresh();
            self::assertNull($fresh->getRawOriginal('observability_context'));
            // A later ordinary update must not publish a candidate from the rolled-back event.
            $fresh->touch();
            self::assertNull($fresh->fresh()->getRawOriginal('observability_context'));
            self::assertTrue($record()['recorded']);
        }, enabled: true);
        $storedTask = $claim->task->fresh();
        $contexts = TaskOutcomeCarrier::fromTask($storedTask);
        self::assertCount(1, $contexts['outcomes']);
        $event = WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->sole();
        self::assertSame($event->id, $contexts['outcomes'][0]['event_id']);
        $stored = $storedTask->getRawOriginal('observability_context');
        $history = WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map(
            static fn ($row): array => $row->getRawOriginal(),
        )->all();
        $workflowTask = WorkflowTask::query()->where('workflow_run_id', $claim->run->id)
            ->where('task_type', 'workflow')
            ->latest('id')
            ->firstOrFail();
        $runAttributes = $claim->run->fresh()
            ->getRawOriginal();
        $taskAttributes = $storedTask->getRawOriginal();
        self::assertSame([
            $event->id => $contexts['outcomes'][0] + [
                'task_id' => $storedTask->id,
            ],
        ], TaskOutcomeCarrier::forRun($claim->run, $workflowTask));
        self::assertSame($taskAttributes, $storedTask->fresh()->getRawOriginal());
        self::assertSame($runAttributes, $claim->run->fresh()->getRawOriginal());
        self::assertSame($history, WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map(
            static fn ($row): array => $row->getRawOriginal(),
        )->all());
        self::assertSame([
            'recorded' => false,
            'reason' => 'stale_attempt',
            'next_task' => null,
        ], $record());
        self::assertSame($stored, $storedTask->fresh()->getRawOriginal('observability_context'));
        self::assertSame($history, WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map(
            static fn ($row): array => $row->getRawOriginal(),
        )->all());
    }

    public function testTaskCarrierMigrationKeepsLegacyRowsAndHasNoImplicitAuthority(): void
    {
        $stub = WorkflowStub::make(TracedWorkflow::class, 'legacy-outcome-task', '0');
        $stub->start(false);
        $task = WorkflowTask::query()->sole();
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_16_000100_add_observability_context_to_workflow_tasks.php';
        $migration->down();
        $before = (array) DB::table('workflow_tasks')->where('id', $task->id)->sole();
        $migration->up();
        $migration->up();
        $after = (array) DB::table('workflow_tasks')->where('id', $task->id)->sole();
        self::assertArrayHasKey('observability_context', $after);
        self::assertNull($after['observability_context']);
        unset($after['observability_context']);
        self::assertSame($before, $after);
        self::assertArrayNotHasKey('observability_context', $task->fresh()->toArray());
    }

    public function testExistingRunMigrationLeavesItsDataUntouchedAndAllowsExecutionWithoutAParent(): void
    {
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'legacy-run', '0');
            $stub->start(false);
        } finally {
            $scope->detach();
        }
        self::assertNull(WorkflowRun::query()->findOrFail($stub->runId())->observability_context);
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_15_000100_add_observability_context_to_workflow_runs.php';
        $migration->down();
        $before = (array) DB::table('workflow_runs')->where('id', $stub->runId())->sole();
        $migration->up();
        $migration->up();
        $after = (array) DB::table('workflow_runs')->where('id', $stub->runId())->sole();
        self::assertArrayHasKey('observability_context', $after);
        self::assertNull($after['observability_context']);
        unset($after['observability_context']);
        self::assertSame($before, $after);
        config([
            'workflows.tracing.enabled' => true,
        ]);
        for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
        }
        self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        $spans = $this->activitySpans();
        self::assertCount(1, $spans);
        self::assertFalse($spans[0]->getParentContext()->isValid());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public static function coldModes(): array
    {
        return [
            'single attempt' => [false],
            'cold retry' => [true],
        ];
    }

    public static function unreadableMetadataModes(): iterable
    {
        foreach (['workflow', 'activity'] as $phase) {
            yield $phase . ', enabled' => [$phase, true];
            yield $phase . ', disabled' => [$phase, false];
        }
    }

    #[DataProvider('unreadableMetadataModes')]
    public function testUnreadableOptionalRunMetadataCannotInterruptClaimedBusinessWork(
        string $phase,
        bool $enabled
    ): void {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'unreadable-tracing', '0');
            $stub->start(false, new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
        } finally {
            $scope->detach();
        }
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $preserved = array_flip(['observability_context', 'execution_deadline_at', 'run_deadline_at']);
        $original = array_intersect_key($run->getRawOriginal(), $preserved);
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map(static fn ($event): array => $event->getRawOriginal())
            ->all();
        if ($phase === 'activity') {
            $this->workOnce();
        }
        UnreadableTraceCast::$metadataReads = 0;
        WorkflowRun::retrieved(static function (WorkflowRun $retrievedRun): void {
            if ($retrievedRun->workflow_instance_id === 'unreadable-tracing') {
                $retrievedRun->mergeCasts([
                    'observability_context' => UnreadableTraceCast::class,
                ]);
            }
        });
        for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
            self::assertSame([], ExecutionTracing::current());
        }
        $run->refresh();
        self::assertSame('completed', $run->status->value);
        self::assertSame([9007199254740993, null, '雪'], $run->workflowOutput());
        self::assertSame($original, array_intersect_key($run->getRawOriginal(), $preserved));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame(3, WorkflowTask::query()->where('status', 'completed')->where('attempt_count', 1)->count());
        self::assertSame(
            1,
            ActivityAttempt::query()->where('status', 'completed')->where('attempt_number', 1)->count()
        );
        self::assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count());
        self::assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'WorkflowCompleted')->count());
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map(static fn ($event): array => $event->getRawOriginal())
            ->all();
        self::assertSame($prefix, array_slice($history, 0, count($prefix)));
        self::assertSame($enabled ? ($phase === 'workflow' ? 3 : 2) : 0, UnreadableTraceCast::$metadataReads);
        $spans = $this->exporter->getSpans();
        self::assertCount($enabled ? 7 : 0, $spans);
        self::assertCount($enabled ? 3 : 0, array_filter(
            $spans,
            static fn ($span): bool => $span->getKind() === SpanKind::KIND_PRODUCER
        ));
        $admissions = array_values(array_filter(
            $spans,
            static fn ($span): bool => $span->getName() === 'durable.workflow.admission'
        ));
        self::assertCount($enabled ? 1 : 0, $admissions);
        if ($enabled) {
            self::assertSame($run->id, $admissions[0]->getAttributes()->get('workflow_run_id'));
            self::assertSame('00f067aa0ba902b7', $admissions[0]->getParentContext()->getSpanId());
        }
        $processing = array_values(array_filter(
            $spans,
            static fn ($span): bool => $span->getKind() === SpanKind::KIND_CONSUMER
        ));
        self::assertCount($enabled ? 3 : 0, $processing);
        foreach ($processing as $index => $span) {
            self::assertSame($run->id, $span->getAttributes()->get('workflow_run_id'));
            self::assertSame($phase === 'activity' && $index === 0, $span->getParentContext()->isValid());
            self::assertSame(
                $phase === 'activity' && $index === 0 ? null : 'discarded',
                $span->getAttributes()
                    ->get('durable.trace_context.disposition')
            );
            self::assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            self::assertStringNotContainsString(
                'optional telemetry cast failed',
                json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR)
            );
        }
        self::assertCount(
            1,
            array_filter(
                TracedActivity::$observations,
                static fn (array $observation): bool => $observation[0] === 'callback'
            )
        );
    }

    public static function unavailableTracingConfiguration(): array
    {
        return [
            'disabled control' => [null, false],
            'unavailable tracing key' => ['workflows.tracing.enabled', false],
            'unavailable baggage allowlist' => ['workflows.tracing.baggage_allowlist', true],
        ];
    }

    #[DataProvider('unavailableTracingConfiguration')]
    public function testOptionalTracingConfigurationCannotReplaceTheBusinessFailure(
        ?string $unavailable,
        bool $enabled
    ): void {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $stub = WorkflowStub::make(FailingTracedWorkflow::class, 'unavailable-tracing-config', '0');
        $stub->start(new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $preserved = array_flip(['observability_context', 'execution_deadline_at', 'run_deadline_at']);
        $original = array_intersect_key($run->getRawOriginal(), $preserved);
        $prefix = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->map(static fn ($event): array => $event->getRawOriginal())
            ->all();
        $configuration = $this->app['config'];
        $this->app->instance('config', $this->unavailableConfiguration($unavailable));
        try {
            $this->workOnce();
        } finally {
            $this->app->instance('config', $configuration);
        }
        $run->refresh();
        self::assertSame('failed', $run->status->value);
        self::assertSame('application detail must not be exported', $run->failures()->sole()->message);
        self::assertSame($original, array_intersect_key($run->getRawOriginal(), $preserved));
        self::assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowFailed')->count());
        self::assertSame($prefix, array_slice($run->historyEvents()->orderBy('sequence')->get()
            ->map(static fn ($event): array => $event->getRawOriginal())
            ->all(), 0, count($prefix)));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertCount($enabled ? 3 : 0, $this->exporter->getSpans());
    }

    #[DataProvider('unavailableTracingConfiguration')]
    public function testOptionalConfigurationCannotBlockAdmissionTimerOrActivity(
        ?string $unavailable,
        bool $enabled
    ): void {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $configuration = $this->app['config'];
        $this->app->instance('config', $this->unavailableConfiguration($unavailable));
        try {
            $stub = WorkflowStub::make(TimerTracedWorkflow::class, 'unavailable-config-completion', '0');
            $stub->start(new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
            $prefix = $run->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all();
            $this->workOnce();
            sleep(2);
            for ($remaining = 4; $remaining > 0; --$remaining) {
                $this->workOnce();
            }
            $run->refresh();
            self::assertSame('completed', $run->status->value);
            self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
            self::assertSame(
                $deadlines,
                [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')]
            );
            self::assertSame($prefix, array_slice($run->historyEvents()->orderBy('sequence')->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all(), 0, count($prefix)));
            self::assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowCompleted')->count());
            self::assertSame(1, $run->historyEvents()->where('event_type', 'ActivityCompleted')->count());
            self::assertSame(1, $run->historyEvents()->where('event_type', 'TimerFired')->count());
            self::assertSame(5, WorkflowTask::query()->where('status', 'completed')->count());
            self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
            self::assertSame(0, DB::table('jobs')->count());
            self::assertSame(0, DB::table('failed_jobs')->count());
            self::assertCount($enabled ? 11 : 0, $this->exporter->getSpans());
        } finally {
            $this->app->instance('config', $configuration);
        }
    }

    #[DataProvider('dispatchFailures')]
    public function testScheduledAdmissionKeepsProcessorHooksOutsideTheScheduleTransaction(bool $enabled): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $admissionContexts = [];
        $this->app['events']->listen(WorkflowStarted::class, static function () use (&$admissionContexts): void {
            $admissionContexts[] = ExecutionTracing::current();
        });
        $processor = new ScheduleTracingProcessor($this->exporter);
        $provider = new TracerProvider($processor);
        $instrumentation = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            $creator = TraceContextPropagator::getInstance()->extract([
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
            ])->activate();
            try {
                $schedule = ScheduleManager::create(
                    scheduleId: 'traced-manual-schedule',
                    workflowClass: TracedWorkflow::class,
                    cronExpression: '* * * * *',
                    arguments: [false],
                    connection: 'database',
                    queue: 'default',
                    namespace: '0',
                    executionTimeoutSeconds: 60,
                    runTimeoutSeconds: 45,
                );
            } finally {
                $creator->detach();
            }
            $parent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
            $caller = TraceContextPropagator::getInstance()->extract([
                'traceparent' => $parent,
            ])->activate();
            try {
                $instanceId = ScheduleManager::trigger($schedule);
            } finally {
                $caller->detach();
            }
            self::assertSame(0, DB::transactionLevel());
            self::assertNotNull($instanceId);
            $run = WorkflowRun::query()->where('workflow_instance_id', $instanceId)->sole();
            self::assertCount(1, $admissionContexts);
            if ($enabled) {
                self::assertSame($instanceId, $admissionContexts[0]['workflow_instance_id'] ?? null);
                self::assertSame($run->workflow_type, $admissionContexts[0]['workflow_type'] ?? null);
                self::assertSame('0', $admissionContexts[0]['namespace'] ?? null);
                self::assertSame('traced-manual-schedule', $admissionContexts[0]['schedule_id'] ?? null);
            } else {
                self::assertSame([], $admissionContexts[0]);
            }
            $preserved = array_intersect_key($run->getRawOriginal(), array_flip([
                'observability_context', 'execution_deadline_at', 'run_deadline_at',
            ]));
            self::assertSame($enabled ? $parent : null, $run->observability_context['carrier']['traceparent'] ?? null);
            $prefix = $run->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all();
            for ($remaining = 3; $remaining > 0; --$remaining) {
                $this->workOnce();
            }
            $run->refresh();
            $schedule->refresh();
            self::assertSame('completed', $run->status->value);
            self::assertSame([9007199254740993, null, '雪'], Serializer::unserializeWithCodec(
                $run->payload_codec,
                $run->output
            ));
            self::assertSame($preserved, array_intersect_key($run->getRawOriginal(), $preserved));
            self::assertSame($prefix, array_slice($run->historyEvents()->orderBy('sequence')->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all(), 0, count($prefix)));
            self::assertSame(1, (int) $schedule->fires_count);
            self::assertSame($instanceId, $schedule->latest_workflow_instance_id);
            self::assertSame(1, $run->historyEvents()->where('event_type', 'ScheduleTriggered')->count());
            self::assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowCompleted')->count());
            self::assertSame(1, $run->historyEvents()->where('event_type', 'ActivityCompleted')->count());
            self::assertSame(3, WorkflowTask::query()->where('status', 'completed')->count());
            self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
            self::assertSame(0, DB::table('jobs')->count());
            self::assertSame(0, DB::table('failed_jobs')->count());
            self::assertCount($enabled ? 7 : 0, $this->exporter->getSpans());
            $triggers = array_values(array_filter(
                $this->exporter->getSpans(),
                static fn ($span): bool => $span->getName() === 'durable.schedule.trigger'
            ));
            self::assertCount($enabled ? 1 : 0, $triggers);
            if ($enabled) {
                self::assertSame('00f067aa0ba902b7', $triggers[0]->getParentContext()->getSpanId());
                self::assertSame($run->id, $triggers[0]->getAttributes()->get('workflow_run_id'));
                self::assertSame('traced-manual-schedule', $triggers[0]->getAttributes()->get('schedule_id'));
                self::assertSame('triggered', $triggers[0]->getAttributes()->get('durable.schedule.outcome'));
                self::assertSame('manual', $triggers[0]->getAttributes()->get('durable.schedule.trigger_kind'));
            }
            self::assertSame(array_fill(0, count($processor->transactionLevels), 0), $processor->transactionLevels);
        } finally {
            $instrumentation->detach();
            $provider->shutdown();
        }
    }

    #[DataProvider('dispatchFailures')]
    public function testScheduledQueueStorageFailurePreservesItsCommittedAdmission(bool $enabled): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
            'queue.connections.database.table' => 'missing_trace_jobs',
        ]);
        $schedule = ScheduleManager::create(
            scheduleId: 'schedule-queue-failure',
            workflowClass: TracedWorkflow::class,
            cronExpression: '* * * * *',
            arguments: [false],
            namespace: '0',
            executionTimeoutSeconds: 60,
            runTimeoutSeconds: 45
        );
        try {
            ScheduleManager::trigger($schedule);
            self::fail('The actual post-commit queue INSERT must fail.');
        } catch (QueryException $failure) {
            self::assertStringContainsString('missing_trace_jobs', $failure->getMessage());
        }
        $schedule->refresh();
        $run = WorkflowRun::query()->sole();
        $task = WorkflowTask::query()->sole();
        self::assertSame(1, (int) $schedule->fires_count);
        self::assertSame($run->workflow_instance_id, $schedule->latest_workflow_instance_id);
        self::assertSame('pending', $run->status->value);
        self::assertSame('ready', $task->status->value);
        self::assertNull($task->last_dispatched_at);
        self::assertStringContainsString('missing_trace_jobs', $task->last_dispatch_error);
        self::assertSame(0, (int) $task->repair_count);
        self::assertTrue($run->started_at->copy()->addSeconds(60)->equalTo($run->execution_deadline_at));
        self::assertTrue($run->started_at->copy()->addSeconds(45)->equalTo($run->run_deadline_at));
        self::assertSame(0, ActivityAttempt::query()->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        $triggers = array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.schedule.trigger'
        ));
        self::assertCount($enabled ? 1 : 0, $triggers);
        if ($enabled) {
            self::assertSame(StatusCode::STATUS_ERROR, $triggers[0]->getStatus()->getCode());
            self::assertSame(QueryException::class, $triggers[0]->getAttributes()->get('error.type'));
            self::assertNull($triggers[0]->getAttributes()->get('durable.schedule.outcome'));
        }
    }

    public static function queuedScheduleKinds(): array
    {
        return [
            'buffered traced' => ['buffered', true],
            'buffered disabled' => ['buffered', false],
            'backfill traced' => ['backfill', true],
            'backfill disabled' => ['backfill', false],
        ];
    }

    #[DataProvider('queuedScheduleKinds')]
    public function testBufferedAndBackfilledRunsKeepTheirAtomicScheduleState(string $kind, bool $enabled): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $processor = new ScheduleTracingProcessor($this->exporter);
        $provider = new TracerProvider($processor);
        $scope = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            $schedule = ScheduleManager::create(
                scheduleId: 'queued-' . $kind,
                workflowClass: TracedWorkflow::class,
                cronExpression: '* * * * *',
                arguments: [false],
                overlapPolicy: $kind === 'buffered' ? ScheduleOverlapPolicy::BufferOne : ScheduleOverlapPolicy::AllowAll,
                namespace: '0',
                executionTimeoutSeconds: 60,
                runTimeoutSeconds: 45
            );
            if ($kind === 'buffered') {
                self::assertNotNull(ScheduleManager::trigger($schedule));
                self::assertNull(ScheduleManager::trigger($schedule));
            } else {
                $from = now()
                    ->startOfMinute()
                    ->subMinutes(2);
                $receipts = ScheduleManager::backfill($schedule, $from, $from->copy()->addMinute());
                self::assertCount(1, $receipts);
                self::assertNotNull($receipts[0]['instance_id']);
            }
            $first = WorkflowRun::query()->sole();
            $original = $first->getRawOriginal();
            $prefix = $first->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all();
            for ($remaining = 3; $remaining > 0; --$remaining) {
                $this->workOnce();
            }
            if ($kind === 'buffered') {
                $receipts = ScheduleManager::tick(1);
                self::assertSame('drained', $receipts[0]['outcome']);
                for ($remaining = 3; $remaining > 0; --$remaining) {
                    $this->workOnce();
                }
            }
            $schedule->refresh();
            $first->refresh();
            foreach (['observability_context', 'execution_deadline_at', 'run_deadline_at'] as $field) {
                self::assertSame($original[$field], $first->getRawOriginal($field));
            }
            self::assertSame($prefix, array_slice($first->historyEvents()->orderBy('sequence')->get()
                ->map(static fn ($event): array => $event->getRawOriginal())
                ->all(), 0, count($prefix)));
            $runCount = $kind === 'buffered' ? 2 : 1;
            self::assertSame($runCount, (int) $schedule->fires_count);
            self::assertSame($runCount, WorkflowRun::query()->where('status', 'completed')->count());
            foreach (WorkflowRun::query()->get() as $run) {
                self::assertSame([9007199254740993, null, '雪'], $run->workflowOutput());
                self::assertTrue($run->started_at->copy()->addSeconds(60)->equalTo($run->execution_deadline_at));
                self::assertTrue($run->started_at->copy()->addSeconds(45)->equalTo($run->run_deadline_at));
            }
            self::assertSame($runCount, ActivityAttempt::query()->where('status', 'completed')->count());
            self::assertSame($runCount * 3, WorkflowTask::query()->where('status', 'completed')->count());
            self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
            self::assertSame(0, DB::table('jobs')->count());
            self::assertSame(0, DB::table('failed_jobs')->count());
            $triggers = array_values(array_filter(
                $this->exporter->getSpans(),
                static fn ($span): bool => $span->getName() === 'durable.schedule.trigger'
            ));
            self::assertCount($enabled ? ($kind === 'buffered' ? 3 : 1) : 0, $triggers);
            if ($enabled) {
                $last = $triggers[count($triggers) - 1];
                self::assertSame($kind, $last->getAttributes()->get('durable.schedule.trigger_kind'));
                self::assertSame(
                    $kind === 'buffered' ? 'drained' : 'triggered',
                    $last->getAttributes()
                        ->get('durable.schedule.outcome')
                );
            }
            self::assertSame(array_fill(0, count($processor->transactionLevels), 0), $processor->transactionLevels);
        } finally {
            $scope->detach();
            $provider->shutdown();
        }
    }

    public function testFailedWorkflowExportsItsProcessingSegmentOutsideTheDatabaseTransaction(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $processor = new class($this->exporter) extends SimpleSpanProcessor {
            public array $transactionLevels = [];

            public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
            }

            public function onEnd(ReadableSpanInterface $span): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
                parent::onEnd($span);
            }
        };
        $provider = new TracerProvider($processor);
        $scope = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            $stub = WorkflowStub::make(FailingTracedWorkflow::class, 'failed-workflow', '0');
            $stub->start();
            $this->workOnce();
            self::assertSame('failed', WorkflowRun::query()->findOrFail($stub->runId())->status->value);
            self::assertSame([0, 0, 0, 0, 0, 0], $processor->transactionLevels);
            $spans = $this->exporter->getSpans();
            self::assertSame(
                ['durable.task.dispatch', 'durable.workflow.admission', 'durable.workflow.task'],
                array_map(static fn ($span): string => $span->getName(), $spans)
            );
            self::assertSame('started_new', $spans[1]->getAttributes()->get('durable.command.outcome'));
            self::assertSame(StatusCode::STATUS_ERROR, $spans[2]->getStatus()->getCode());
            self::assertSame(RuntimeException::class, $spans[2]->getAttributes()->get('error.type'));
            self::assertSame($stub->runId(), FailingTracedWorkflow::$fields['workflow_run_id']);
            self::assertSame($spans[2]->getContext()->getSpanId(), FailingTracedWorkflow::$fields['span_id']);
            self::assertSame([], ExecutionTracing::current());
            self::assertSame(0, DB::table('failed_jobs')->count());
        } finally {
            $scope->detach();
            $provider->shutdown();
        }
    }

    public function testCommandCarrierMigrationPreservesLegacyRowsAndKeepsMetadataOutOfPublicContext(): void
    {
        $stub = WorkflowStub::make(TracedWorkflow::class, 'legacy-command', '0');
        $stub->start(false);
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_15_000200_add_observability_context_to_workflow_commands.php';
        $migration->down();
        $before = (array) DB::table('workflow_commands')->sole();
        $migration->up();
        $migration->up();
        $after = (array) DB::table('workflow_commands')->sole();
        self::assertArrayHasKey('observability_context', $after);
        self::assertNull($after['observability_context']);
        unset($after['observability_context']);
        self::assertSame($before, $after);
        $command = WorkflowCommand::query()->sole();
        $context = $command->commandContext();
        $publicContext = $command->publicContext();
        $command->observability_context = [
            'schema' => 'durable-workflow.trace-context',
            'version' => 1,
            'carrier' => [
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
            ],
        ];
        self::assertSame($context, $command->commandContext());
        self::assertSame($publicContext, $command->publicContext());
        self::assertArrayNotHasKey('observability_context', $command->toArray());
    }

    #[DataProvider('coldModes')]
    public function testIndependentQueueProcessesUseThePersistedAdmissionParent(bool $retry): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $run = static function (string $mode) use ($directory, $retry): array {
            $packageVendor = dirname(__DIR__, 3) . '/vendor';
            $otherVendors = array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                static fn (string $vendor): bool => $vendor !== $packageVendor
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, $retry ? 'retry' : 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    [...$otherVendors, $packageVendor]
                ),
            ]);
            $process->setTimeout(30);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            self::assertJson($process->getOutput(), $mode . ': ' . substr($process->getOutput(), 0, 2048));
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
        try {
            $admitted = $run('admit');
            $duplicate = $run('duplicate');
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $duplicate[$key]);
            }
            $workflow = $run('work');
            $activity = $run('work');
            $retried = $retry ? $run('work') : null;
            $completion = $run('work');
            $workerPids = [$workflow['pid'], $activity['pid'], $completion['pid']];
            if ($retried !== null) {
                $workerPids[] = $retried['pid'];
                self::assertCount(1, $retried['execution_spans']);
                self::assertSame(
                    $activity['execution_spans'][0]['fields']['activity_id'],
                    $retried['execution_spans'][0]['fields']['activity_id']
                );
                self::assertNotSame(
                    $activity['execution_spans'][0]['fields']['activity_attempt_id'],
                    $retried['execution_spans'][0]['fields']['activity_attempt_id']
                );
                self::assertNotSame(
                    $activity['execution_spans'][0]['span_id'],
                    $retried['execution_spans'][0]['span_id']
                );
                self::assertSame(
                    $activity['execution_spans'][0]['trace_id'],
                    $retried['execution_spans'][0]['trace_id']
                );
                self::assertSame(
                    $activity['execution_spans'][0]['parent_id'],
                    $retried['execution_spans'][0]['parent_id']
                );
            }
            self::assertCount($retry ? 4 : 3, array_unique($workerPids));
            foreach ([$workflow, $completion] as $segment) {
                self::assertCount(1, $segment['execution_spans']);
                self::assertSame('durable.workflow.task', $segment['execution_spans'][0]['name']);
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $segment['execution_spans'][0]['trace_id']);
                self::assertSame('00f067aa0ba902b7', $segment['execution_spans'][0]['parent_id']);
                self::assertSame($admitted['run_id'], $segment['execution_spans'][0]['fields']['workflow_run_id']);
                self::assertArrayNotHasKey('activity_attempt_id', $segment['execution_spans'][0]['fields']);
            }
            self::assertNotSame(
                $workflow['execution_spans'][0]['span_id'],
                $completion['execution_spans'][0]['span_id']
            );
            self::assertNotSame(
                $workflow['execution_spans'][0]['fields']['task_id'],
                $completion['execution_spans'][0]['fields']['task_id']
            );
            self::assertCount(1, $activity['execution_spans']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $activity['execution_spans'][0]['trace_id']);
            self::assertSame('00f067aa0ba902b7', $activity['execution_spans'][0]['parent_id']);
            $finished = $run('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($retry ? 4 : 3, $finished['tasks']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(
                array_filter($records, static fn (array $record): bool => $record['message'] === 'callback')
            );
            self::assertCount($retry ? 2 : 1, $callbacks);
            foreach ($callbacks as $callback) {
                self::assertSame($admitted['run_id'], $callback['context']['workflow_run_id']);
                self::assertSame($activity['execution_spans'][0]['trace_id'], $callback['context']['trace_id']);
                self::assertArrayHasKey('activity_attempt_id', $callback['context']);
                self::assertSame('0', $callback['context']['namespace']);
                $constructed = array_values(
                    array_filter(
                        $records,
                        static fn (array $record): bool => $record['message'] === 'constructor' && ($record['context']['activity_attempt_id'] ?? null) === $callback['context']['activity_attempt_id']
                    )
                );
                self::assertCount(1, $constructed);
                self::assertSame($callback['context'], $constructed[0]['context']);
                $dependencies = array_values(
                    array_filter(
                        $records,
                        static fn (array $record): bool => $record['message'] === 'dependency' && ($record['context']['activity_attempt_id'] ?? null) === $callback['context']['activity_attempt_id']
                    )
                );
                self::assertCount(2, $dependencies);
                foreach ($dependencies as $dependency) {
                    self::assertSame($callback['context'], $dependency['context']);
                }
            }
            self::assertCount(
                $retry ? 4 : 3,
                array_filter($records, static fn (array $record): bool => $record['message'] === 'worker outside')
            );
            foreach ($records as $record) {
                if ($record['message'] === 'worker outside') {
                    self::assertArrayNotHasKey('workflow_run_id', $record['context']);
                } elseif (isset($record['context']['activity_attempt_id'])) {
                    self::assertSame($admitted['run_id'], $record['context']['workflow_run_id']);
                    self::assertSame($activity['execution_spans'][0]['trace_id'], $record['context']['trace_id']);
                }
            }
            $workflowLogs = array_values(array_filter(
                $records,
                static fn (array $record): bool => str_starts_with($record['message'], 'workflow ')
            ));
            self::assertCount(9, $workflowLogs);
            $segments = [];
            foreach ([$workflow, $completion] as $segment) {
                $span = $segment['execution_spans'][0];
                self::assertArrayHasKey('durable.task.queue_wait_seconds', $span['fields']);
                self::assertGreaterThanOrEqual(0, $span['fields']['durable.task.queue_wait_seconds']);
                $correlation = $span['fields'];
                unset($correlation['durable.task.queue_wait_seconds']);
                $segments[$span['fields']['task_id']] = $correlation + [
                    'trace_id' => $span['trace_id'],
                    'span_id' => $span['span_id'],
                ];
            }
            foreach ($workflowLogs as $record) {
                self::assertArrayNotHasKey('durable.task.queue_wait_seconds', $record['context']);
                self::assertArrayHasKey($record['context']['task_id'], $segments);
                self::assertSame($segments[$record['context']['task_id']], $record['context']);
            }
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    #[DataProvider('modes')]
    public function testDatabaseQueueScopesDependenciesAndPreservesOriginalTaskRetries(bool $enabled, bool $retry): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $stub = WorkflowStub::make(TracedWorkflow::class, 'tracing-original', '0');
        $stub->start($retry);
        $this->workOnce();
        // Scheduling can inspect an activity's policy. Only actual execution is traced.
        TracedActivity::$observations = [];
        for ($remaining = 6; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
            self::assertSame([], ExecutionTracing::current());
        }
        $stub->refresh();
        self::assertSame([9007199254740993, null, '雪'], $stub->output());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame($retry ? 2 : 1, ActivityAttempt::query()->count());
        $spans = $this->activitySpans();
        self::assertCount($enabled ? ($retry ? 2 : 1) : 0, $spans);
        $attempts = [];
        foreach (TracedActivity::$observations as [$phase, $fields]) {
            if (! $enabled) {
                self::assertSame([], $fields);
                continue;
            }
            self::assertSame($stub->runId(), $fields['workflow_run_id']);
            self::assertSame('tracing-original', $fields['workflow_instance_id']);
            self::assertSame('0', $fields['namespace']);
            self::assertNotEmpty($fields['trace_id']);
            self::assertNotEmpty($fields['span_id']);
            $attempts[$fields['activity_attempt_id']][$phase] = $fields;
        }
        if ($enabled) {
            self::assertCount($retry ? 2 : 1, $attempts);
            foreach ($attempts as $phases) {
                self::assertSame($phases['dependency'], $phases['constructor']);
                self::assertSame($phases['constructor'], $phases['callback']);
            }
            if ($retry) {
                self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
                self::assertSame(RuntimeException::class, $spans[0]->getAttributes()->get('error.type'));
                self::assertNotSame($spans[0]->getContext()->getSpanId(), $spans[1]->getContext()->getSpanId());
                self::assertSame(
                    $spans[0]->getAttributes()->get('activity_id'),
                    $spans[1]->getAttributes()->get('activity_id')
                );
            }
        }
    }

    public static function localModes(): array
    {
        return [
            'disabled' => [false, false, false],
            'enabled' => [true, false, false],
            'legacy' => [true, false, true],
            'retry' => [true, true, false],
        ];
    }

    #[DataProvider('localModes')]
    public function testLocalAttemptsKeepTheHostingTaskParentAcrossRetryAndHistoryReplay(
        bool $enabled,
        bool $retry,
        bool $legacy,
    ): void {
        config([
            'workflows.tracing.enabled' => $enabled && ! $legacy,
        ]);
        $stub = WorkflowStub::make(LocalTracedWorkflow::class, 'local-tracing-original', '0');
        $stub->start($retry, new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        if ($legacy) {
            self::assertNull($run->observability_context);
        }
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        for ($remaining = 10; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
            self::assertSame([], ExecutionTracing::current());
        }
        self::assertSame('completed', $run->fresh()->status->value);
        self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame(0, WorkflowTask::query()->where('task_type', 'activity')->count());
        $run->refresh();
        self::assertSame($deadlines, [
            $run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at'),
        ]);
        $attempts = ActivityAttempt::query()->get()->keyBy('id');
        self::assertCount($retry ? 2 : 1, $attempts);
        $execution = ActivityExecution::query()->sole();
        $spans = array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.local_activity.attempt'
        ));
        self::assertCount($enabled ? ($retry ? 2 : 1) : 0, $spans);
        $parents = [];
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === 'durable.workflow.task') {
                $parents[$span->getContext()->getSpanId()] = $span;
            }
        }
        foreach ($spans as $span) {
            self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
            $fields = $span->getAttributes();
            $attempt = $attempts[$fields->get('activity_attempt_id')];
            self::assertSame($execution->id, $fields->get('activity_id'));
            self::assertSame($attempt->attempt_number, $fields->get('activity_attempt_number'));
            self::assertSame($attempt->workflow_task_id, $fields->get('task_id'));
            self::assertNull($fields->get('durable.task.queue_wait_seconds'));
            $parent = $parents[$span->getParentContext()->getSpanId()];
            self::assertSame($span->getContext()->getTraceId(), $parent->getContext()->getTraceId());
            self::assertSame($fields->get('task_id'), $parent->getAttributes()->get('task_id'));
        }
        $callbacks = array_values(array_filter(
            TracedActivity::$observations,
            static fn (array $observation): bool => $observation[0] === 'callback'
        ));
        self::assertCount($retry ? 2 : 1, $callbacks);
        foreach ($callbacks as [, $fields]) {
            if ($enabled) {
                self::assertSame($execution->id, $fields['activity_id']);
                self::assertArrayHasKey($fields['activity_attempt_id'], $attempts->all());
            } else {
                self::assertSame([], $fields);
            }
        }
        foreach (LocalTracedWorkflow::$scopes as [$before, $after]) {
            self::assertSame($before, $after);
            self::assertArrayNotHasKey('activity_attempt_id', $after);
        }
        if ($enabled) {
            foreach ($attempts as $attempt) {
                $observed = array_column(array_filter(
                    TracedActivity::$observations,
                    static fn (array $entry): bool => ($entry[1]['activity_attempt_id'] ?? null) === $attempt->id,
                ), 0);
                self::assertContains('constructor', $observed);
                self::assertContains('dependency', $observed);
                self::assertContains('callback', $observed);
            }
        }
        if ($enabled && $retry) {
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame(RuntimeException::class, $spans[0]->getAttributes()->get('error.type'));
            self::assertNotSame($spans[0]->getContext()->getSpanId(), $spans[1]->getContext()->getSpanId());
        }
        self::assertStringNotContainsString('observability_context', json_encode(
            WorkflowHistoryEvent::query()->pluck('payload')->all(),
            JSON_THROW_ON_ERROR,
        ));
    }

    public static function unrelatedLocalParents(): array
    {
        return [
            'other instance' => ['workflow_instance_id', true],
            'other run' => ['workflow_run_id', true],
            'other namespace' => ['namespace', true],
            'other task' => ['task_id', true],
            'legacy without stored parent' => ['task_id', false],
        ];
    }

    #[DataProvider('unrelatedLocalParents')]
    public function testLocalAttemptNeverInheritsAnUnrelatedHostingTask(string $mismatch, bool $stored): void
    {
        $fields = [
            'workflow_instance_id' => 'local-instance',
            'workflow_run_id' => 'local-run',
            'namespace' => '0',
            'task_id' => 'local-task',
        ];
        $other = array_replace($fields, [
            $mismatch => 'unrelated',
        ]);
        $carrier = $stored ? [
            'schema' => 'durable-workflow.trace-context',
            'version' => 1,
            'carrier' => [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            ],
        ] : null;
        ExecutionTracing::workflow($other, null, static function () use ($fields, $carrier): void {
            $before = ExecutionTracing::current();
            self::assertSame('original result', ExecutionTracing::localActivity(
                $fields,
                $carrier,
                static function () use ($fields): string {
                    self::assertSame($fields['task_id'], ExecutionTracing::current()['task_id']);

                    return 'original result';
                },
                enabled: true,
            ));
            self::assertSame($before, ExecutionTracing::current());
        }, enabled: true);
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        $local = $spans[0];
        $ambient = $spans[1];
        self::assertSame('durable.local_activity.attempt', $local->getName());
        self::assertNotSame($ambient->getContext()->getSpanId(), $local->getParentContext()->getSpanId());
        if ($stored) {
            self::assertSame('00f067aa0ba902b7', $local->getParentContext()->getSpanId());
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $local->getContext()->getTraceId());
        } else {
            self::assertFalse($local->getParentContext()->isValid());
        }
    }

    public function testMockedLocalActivityDoesNotClaimACallbackSpan(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $expected = [9007199254740993, null, '雪'];
        WorkflowStub::mock(TracedActivity::class, $expected);
        $stub = WorkflowStub::make(LocalTracedWorkflow::class, 'mocked-local-tracing', '0');
        $stub->start(false, false);
        self::assertSame($expected, $stub->refresh()->output());
        self::assertSame([], array_values(array_filter(
            TracedActivity::$observations,
            static fn (array $entry): bool => $entry[0] === 'callback',
        )));
        self::assertSame([], array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.local_activity.attempt',
        )));
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('workflows.storage.connection', 'testing');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('workflows.v2.compatibility.namespace', '0');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'stderr');
    }

    private function unavailableConfiguration(?string $unavailable): Repository
    {
        return new class($this->app['config']->all(), $unavailable) extends Repository {
            public function __construct(
                array $items,
                private readonly ?string $unavailable
            ) {
                parent::__construct($items);
            }

            public function get($key, $default = null)
            {
                if ($key === $this->unavailable) {
                    throw new RuntimeException('Optional tracing setting is unavailable');
                }

                return parent::get($key, $default);
            }
        };
    }

    private function workOnce(): void
    {
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]));
    }

    private function activitySpans(): array
    {
        return array_values(array_filter(
            $this->exporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'durable.activity.attempt'
        ));
    }
}

final class ScheduleTracingProcessor extends SimpleSpanProcessor
{
    public array $transactionLevels = [];

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->transactionLevels[] = DB::transactionLevel();
        parent::onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->transactionLevels[] = DB::transactionLevel();
        parent::onEnd($span);
    }
}

final class UnreadableTraceCast implements CastsAttributes
{
    public static int $metadataReads = 0;

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        ++self::$metadataReads;
        throw new RuntimeException('optional application telemetry cast failed');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

final class ParentTracedWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::child(TracedWorkflow::class, false);
    }
}

final class TracedWorkflow extends Workflow
{
    public function handle(bool $retry): array
    {
        return self::activity(TracedActivity::class, $retry);
    }
}

final class TimerTracedWorkflow extends Workflow
{
    public function handle(): array
    {
        self::timer(1);

        return self::activity(TracedActivity::class, false);
    }
}

final class LocalTracedWorkflow extends Workflow
{
    public static array $scopes = [];

    public function handle(bool $retry, bool $wait = true): array
    {
        $before = ExecutionTracing::current();
        try {
            $result = self::localActivity(TracedActivity::class, $retry);
        } finally {
            self::$scopes[] = [$before, ExecutionTracing::current()];
        }
        if ($wait) {
            self::timer(1);
        }

        return $result;
    }
}

final class FailingTracedWorkflow extends Workflow
{
    public static array $fields = [];

    public function handle(): never
    {
        self::$fields = ExecutionTracing::current();
        throw new RuntimeException('application detail must not be exported');
    }
}

final class TracedDependency
{
    public function __construct()
    {
        TracedActivity::$observations[] = ['dependency', ExecutionTracing::current()];
    }
}

final class TracedActivity extends Activity
{
    public static array $observations = [];

    public int $tries = 2;

    public function __construct(TracedDependency $dependency)
    {
        self::$observations[] = ['constructor', ExecutionTracing::current()];
    }

    public function backoff(): int
    {
        return 0;
    }

    public function handle(bool $retry, TracedDependency $dependency): array
    {
        self::$observations[] = ['callback', ExecutionTracing::current()];
        if ($retry && $this->attemptCount() === 1) {
            throw new RuntimeException('business failure must not become exported text');
        }

        return [9007199254740993, null, '雪'];
    }
}
