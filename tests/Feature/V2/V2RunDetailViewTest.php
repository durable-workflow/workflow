<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestAbstractReplayedException;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedFailureWorkflow;
use Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelChildWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnContinuingChildWorkflow;
use Tests\Fixtures\V2\TestSignalOrderingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\Fixtures\V2\TestUnsafeDeterminismWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\WorkflowStub;

final class V2RunDetailViewTest extends TestCase
{
    public function testOperatorObservabilityRepositoryIsBoundToDefaultV2Payloads(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $exportedAt = Carbon::parse('2026-04-09 12:05:00');
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'operator-observability-contract');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        /** @var OperatorObservabilityRepository $repository */
        $repository = app(OperatorObservabilityRepository::class);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = $repository->runDetail($run->fresh());
        $export = $repository->runHistoryExport($run->fresh(), $exportedAt);
        $dashboard = $repository->dashboardSummary($exportedAt);
        $metrics = $repository->metrics($exportedAt);

        $this->assertSame('operator-observability-contract', $detail['instance_id']);
        $this->assertSame($run->id, $detail['run_id']);
        $this->assertSame('durable-workflow.v2.history-export', $export['schema']);
        $this->assertSame($run->id, $export['workflow']['run_id']);
        $this->assertSame($exportedAt->toJSON(), $export['exported_at']);
        $this->assertSame(1, $dashboard['flows']);
        $this->assertSame($exportedAt->toJSON(), $dashboard['operator_metrics']['generated_at']);
        $this->assertSame($exportedAt->toJSON(), $metrics['generated_at']);
        $this->assertArrayHasKey('runs', $metrics);
    }

    public function testMultipleReplayBlockedFailuresStayOrderedAcrossDetailAndExport(): void
    {
        $instance = WorkflowInstance::create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => RunStatus::Failed->value,
            'closed_reason' => RunStatus::Failed->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $earlierFailureId = (string) Str::ulid();
        $laterFailureId = (string) Str::ulid();

        WorkflowFailure::create([
            'id' => $earlierFailureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-earlier',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => 'App\\Legacy\\EarlierFailure',
            'message' => 'earlier replay-blocked failure',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'earlier trace',
            'created_at' => now()
                ->subMinutes(8),
            'updated_at' => now()
                ->subMinutes(8),
        ]);
        WorkflowFailure::create([
            'id' => $laterFailureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-later',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => TestAbstractReplayedException::class,
            'message' => 'later replay-blocked failure',
            'file' => __FILE__,
            'line' => 84,
            'trace_preview' => 'later trace',
            'created_at' => now()
                ->subMinutes(6),
            'updated_at' => now()
                ->subMinutes(6),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $export = HistoryExport::forRun($run->fresh());

        $this->assertSame(2, $detail['exception_count']);
        $this->assertSame([$earlierFailureId, $laterFailureId], collect($detail['exceptions'])->pluck('id')->all());
        $this->assertSame(
            ['unresolved', 'unrestorable'],
            collect($detail['exceptions'])->pluck('exception_resolution_source')->all()
        );
        $this->assertSame([true, true], collect($detail['exceptions'])->pluck('exception_replay_blocked')->all());
        $this->assertSame(
            ['failure_row_fallback', 'failure_row_fallback'],
            collect($detail['exceptions'])->pluck('history_authority')->all()
        );
        $this->assertSame([true, true], collect($detail['exceptions'])->pluck('diagnostic_only')->all());

        $this->assertSame([$earlierFailureId, $laterFailureId], collect($export['failures'])->pluck('id')->all());
        $this->assertSame(
            ['unresolved', 'unrestorable'],
            collect($export['failures'])->pluck('exception_resolution_source')->all()
        );
        $this->assertSame([true, true], collect($export['failures'])->pluck('exception_replay_blocked')->all());
        $this->assertSame(
            ['failure_row_fallback', 'failure_row_fallback'],
            collect($export['failures'])->pluck('history_authority')->all()
        );
        $this->assertSame([true, true], collect($export['failures'])->pluck('diagnostic_only')->all());
    }

    public function testRunDetailViewIncludesWorkflowDeterminismDiagnostics(): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'detail-determinism',
            'workflow_class' => TestUnsafeDeterminismWorkflow::class,
            'workflow_type' => TestUnsafeDeterminismWorkflow::class,
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNDETERMINISM01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUnsafeDeterminismWorkflow::class,
            'workflow_type' => TestUnsafeDeterminismWorkflow::class,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('warning', $detail['workflow_determinism_status']);
        $this->assertSame('live_definition', $detail['workflow_determinism_source']);

        $findings = collect($detail['workflow_determinism_findings'])->keyBy('rule');

        $this->assertTrue($findings->has('workflow_database_facade_call'));
        $this->assertTrue($findings->has('workflow_cache_facade_call'));
        $this->assertTrue($findings->has('workflow_ambient_context_call'));
        $this->assertTrue($findings->has('workflow_wall_clock_call'));
        $this->assertTrue($findings->has('workflow_random_call'));

        $symbols = collect($detail['workflow_determinism_findings'])->pluck('symbol')->all();

        $this->assertContains('DB::table', $symbols);
        $this->assertContains('Cache::get', $symbols);
        $this->assertContains('request', $symbols);
        $this->assertContains('now', $symbols);
        $this->assertContains('random_int', $symbols);
        $this->assertContains('Str::uuid', $symbols);
        $this->assertNotNull($detail['workflow_determinism_findings'][0]['file']);
        $this->assertIsInt($detail['workflow_determinism_findings'][0]['line']);
    }

    public function testRunDetailViewFlagsDefinitionDriftBeforeLiveDeterminismFindings(): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'detail-determinism-drift',
            'workflow_class' => TestUnsafeDeterminismWorkflow::class,
            'workflow_type' => 'workflow.test-unsafe-determinism',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNDETERMINISM02',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUnsafeDeterminismWorkflow::class,
            'workflow_type' => 'workflow.test-unsafe-determinism',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYDETERMINISM0002',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => TestUnsafeDeterminismWorkflow::class,
                'workflow_type' => 'workflow.test-unsafe-determinism',
                'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint(TestGreetingWorkflow::class),
            ],
            'recorded_at' => now()
                ->subMinute(),
        ]);

        $detail = RunDetailView::forRun($run->fresh(['summary', 'historyEvents']));

        $this->assertFalse($detail['workflow_definition_matches_current']);
        $this->assertSame('warning', $detail['workflow_determinism_status']);
        $this->assertSame('definition_drift', $detail['workflow_determinism_source']);
        $this->assertSame([
            [
                'rule' => 'workflow_definition_drift',
                'severity' => 'warning',
                'symbol' => 'workflow.test-unsafe-determinism',
                'message' => 'Selected run started on a different workflow definition fingerprint than the current build. Live-definition replay-safety diagnostics are not authoritative for this run.',
                'file' => null,
                'line' => null,
            ],
        ], $detail['workflow_determinism_findings']);
    }

    public function testRunDetailViewIncludesResumeSourceForReadyWorkflowTask(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'detail-ready-workflow-task');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $workflowTask = $this->findTask($detail['tasks'], 'workflow');

        $this->assertSame('workflow-task', $detail['wait_kind']);
        $this->assertSame('Workflow task ready', $detail['wait_reason']);
        $this->assertSame('workflow-task:' . $workflowTask['id'], $detail['open_wait_id']);
        $this->assertSame('workflow_task', $detail['resume_source_kind']);
        $this->assertSame($workflowTask['id'], $detail['resume_source_id']);
        $this->assertSame($workflowTask['id'], $detail['next_task_id']);
    }

    public function testRunDetailViewIncludesSyntheticMissingWorkflowTaskWhenResumeSourceIsMissing(): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'detail-missing-workflow-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $missingTask = $this->findTask($detail['tasks'], 'workflow');

        $this->assertNull($detail['wait_kind']);
        $this->assertNull($detail['open_wait_id']);
        $this->assertSame(0, $detail['open_wait_count']);
        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('Run is non-terminal but has no durable next-resume source.', $detail['liveness_reason']);
        $this->assertTrue($detail['can_repair']);
        $this->assertSame('missing:workflow:' . $run->id, $missingTask['id']);
        $this->assertSame('workflow', $missingTask['type']);
        $this->assertSame('missing', $missingTask['status']);
        $this->assertSame('missing', $missingTask['transport_state']);
        $this->assertTrue($missingTask['task_missing']);
        $this->assertTrue($missingTask['synthetic']);
        $this->assertSame('Workflow task missing for selected run.', $missingTask['summary']);
        $this->assertNull($missingTask['workflow_wait_kind']);
        $this->assertNull($missingTask['workflow_open_wait_id']);
        $this->assertSame($run->last_progress_at?->toJSON(), $missingTask['available_at']?->toJSON());
    }

    public function testRunDetailViewIncludesWaitAndLivenessMetadataForSignalWaitingRun(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);

        $this->assertSame($runId, $detail['id']);
        $this->assertSame('detail-signal', $detail['instance_id']);
        $this->assertSame($runId, $detail['selected_run_id']);
        $this->assertSame($runId, $detail['run_id']);
        $this->assertTrue($detail['is_current_run']);
        $this->assertSame($runId, $detail['current_run_id']);
        $this->assertSame('waiting', $detail['status']);
        $this->assertSame('running', $detail['status_bucket']);
        $this->assertFalse($detail['is_terminal']);
        $this->assertSame('signal', $detail['wait_kind']);
        $this->assertSame('Waiting for signal name-provided', $detail['wait_reason']);
        $this->assertSame('waiting_for_signal', $detail['liveness_state']);
        $this->assertSame('Waiting for signal name-provided.', $detail['liveness_reason']);
        $this->assertSame('selected_run', $detail['activities_scope']);
        $this->assertSame('selected_run', $detail['commands_scope']);
        $this->assertSame('selected_run', $detail['waits_scope']);
        $this->assertSame('selected_run', $detail['tasks_scope']);
        $this->assertSame('selected_run', $detail['timeline_scope']);
        $this->assertSame('selected_run', $detail['lineage_scope']);
        $this->assertTrue($detail['can_issue_terminal_commands']);
        $this->assertTrue($detail['can_cancel']);
        $this->assertNull($detail['cancel_blocked_reason']);
        $this->assertTrue($detail['can_terminate']);
        $this->assertNull($detail['terminate_blocked_reason']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertTrue($detail['can_update']);
        $this->assertNull($detail['update_blocked_reason']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('repair_not_needed', $detail['repair_blocked_reason']);
        $this->assertNull($detail['read_only_reason']);
        $this->assertSame(1, $detail['commands'][0]['sequence']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame('started_new', $detail['commands'][0]['outcome']);
        $this->assertNull($detail['commands'][0]['requested_run_id']);
        $this->assertSame($runId, $detail['commands'][0]['resolved_run_id']);
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');
        $this->assertSame($signalWait['signal_wait_id'], $detail['open_wait_id']);
        $this->assertSame('signal', $detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
        $this->assertSame('Waiting for signal name-provided.', $signalWait['summary']);
        $this->assertFalse($signalWait['task_backed']);
        $this->assertTrue($signalWait['external_only']);
        $this->assertSame('signal', $signalWait['resume_source_kind']);
        $this->assertNull($signalWait['resume_source_id']);
        $workflowTask = $this->findTask($detail['tasks'], 'workflow');
        $this->assertSame('completed', $workflowTask['status']);
        $this->assertFalse($workflowTask['is_open']);
        $this->assertSame(1, $detail['timeline'][0]['command_sequence']);
        $this->assertSame('SignalWaitOpened', $detail['timeline'][2]['type']);
        $this->assertSame('signal', $detail['timeline'][2]['kind']);
        $this->assertSame('Waiting for signal name-provided.', $detail['timeline'][2]['summary']);
    }

    public function testRunDetailViewReadsWaitRowsFromRebuildableProjection(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-projected-wait');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with('summary')
            ->findOrFail($runId);
        $detail = RunDetailView::forRun($run);
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');

        $this->assertSame('workflow_run_waits_rebuilt', $detail['waits_projection_source']);
        $this->assertDatabaseHas('workflow_run_waits', [
            'workflow_run_id' => $runId,
            'workflow_instance_id' => 'detail-projected-wait',
            'wait_id' => $signalWait['id'],
            'kind' => 'signal',
            'status' => 'open',
            'target_name' => 'name-provided',
            'task_backed' => false,
            'external_only' => true,
        ]);

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->delete();

        $projectedDetail = RunDetailView::forRun(
            WorkflowRun::query()
                ->with(['summary', 'waits'])
                ->findOrFail($runId)
        );

        $this->assertContains($projectedDetail['waits_projection_source'], [
            'workflow_run_waits',
            'workflow_run_waits_rebuilt',
        ]);

        if ($projectedDetail['waits'] === []) {
            $this->assertDatabaseMissing('workflow_run_waits', [
                'workflow_run_id' => $runId,
                'wait_id' => $signalWait['id'],
            ]);
        } else {
            $projectedSignalWait = $this->findWait($projectedDetail['waits'], 'signal', 'name-provided');

            $this->assertSame($signalWait['id'], $projectedSignalWait['id']);
            $this->assertSame('open', $projectedSignalWait['status']);
        }

        WorkflowRunWait::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $fallbackDetail = RunDetailView::forRun(WorkflowRun::query() ->with('summary') ->findOrFail($runId));

        $this->assertContains($fallbackDetail['waits_projection_source'], [
            'workflow_run_waits',
            'workflow_run_waits_rebuilt',
        ]);
    }

    public function testRunDetailViewReadsTimerRowsFromRebuildableProjection(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-projected-timer');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with('summary')
            ->findOrFail($runId);
        $detail = RunDetailView::forRun($run);

        $this->assertSame('workflow_run_timer_entries', $detail['timers_projection_source']);
        $this->assertCount(1, $detail['timers']);
        $this->assertDatabaseHas('workflow_run_timer_entries', [
            'workflow_run_id' => $runId,
            'workflow_instance_id' => 'detail-projected-timer',
            'timer_id' => $detail['timers'][0]['id'],
            'status' => 'pending',
            'source_status' => 'pending',
            'history_authority' => 'typed_history',
        ]);

        WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $projectedDetail = RunDetailView::forRun(
            WorkflowRun::query()
                ->with(['summary', 'timerEntries'])
                ->findOrFail($runId)
        );

        $this->assertSame('workflow_run_timer_entries_rebuilt', $projectedDetail['timers_projection_source']);
        $this->assertCount(1, $projectedDetail['timers']);
        $this->assertSame($detail['timers'][0]['id'], $projectedDetail['timers'][0]['id']);
        $this->assertSame('pending', $projectedDetail['timers'][0]['status']);
        $this->assertSame('typed_history', $projectedDetail['timers'][0]['history_authority']);

        WorkflowRunTimerEntry::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $fallbackDetail = RunDetailView::forRun(WorkflowRun::query() ->with('summary') ->findOrFail($runId));

        $this->assertSame('workflow_run_timer_entries_rebuilt', $fallbackDetail['timers_projection_source']);
        $this->assertCount(1, $fallbackDetail['timers']);
        $this->assertDatabaseHas('workflow_run_timer_entries', [
            'workflow_run_id' => $runId,
            'timer_id' => $detail['timers'][0]['id'],
        ]);
    }

    public function testRunDetailViewFallsBackToTypedChildWaitsWhenSummaryHasNoCurrentWait(): void
    {
        $parentInstance = WorkflowInstance::create([
            'id' => 'detail-child-summary-no-wait-parent',
            'workflow_class' => TestParentChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'detail-child-summary-no-wait-child',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::create([
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => TestParentChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'workflow.child',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(3),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $parentInstance->update([
            'current_run_id' => $parentRun->id,
        ]);
        $childInstance->update([
            'current_run_id' => $childRun->id,
        ]);

        $link = WorkflowLink::create([
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::record($parentRun->refresh(), HistoryEventType::ChildWorkflowScheduled, [
            'workflow_link_id' => $link->id,
            'child_call_id' => $link->id,
            'sequence' => 1,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_type' => 'workflow.child',
            'child_workflow_class' => TestGreetingWorkflow::class,
            'child_run_number' => 1,
        ]);

        WorkflowHistoryEvent::record($parentRun->refresh(), HistoryEventType::ChildRunCompleted, [
            'workflow_link_id' => $link->id,
            'child_call_id' => $link->id,
            'sequence' => 1,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_type' => 'workflow.child',
            'child_workflow_class' => TestGreetingWorkflow::class,
            'child_run_number' => 1,
            'child_status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
        ]);

        WorkflowRunSummary::create([
            'id' => $parentRun->id,
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestParentChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $parentRun->started_at,
            'closed_at' => $parentRun->closed_at,
            'duration_ms' => 180000,
        ]);

        $detail = RunDetailView::forRun(
            WorkflowRun::query()
                ->with(['summary', 'waits'])
                ->findOrFail($parentRun->id)
        );
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('workflow_run_waits_rebuilt', $detail['waits_projection_source']);
        $this->assertSame('completed', $detail['status']);
        $this->assertNull($detail['wait_kind']);
        $this->assertSame('resolved', $childWait['status']);
        $this->assertSame('completed', $childWait['source_status']);
        $this->assertSame($link->id, $childWait['child_call_id']);
        $this->assertSame($childRun->id, $childWait['resume_source_id']);
        $this->assertDatabaseHas('workflow_run_waits', [
            'workflow_run_id' => $parentRun->id,
            'wait_id' => $childWait['id'],
            'kind' => 'child',
            'resume_source_id' => $childRun->id,
        ]);
    }

    public function testRunDetailViewExposesNormalizedCommandTargetsForMixedSignalContracts(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $workflow = WorkflowStub::make(TestCommandTargetWorkflow::class, 'detail-command-targets');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());

        $detail = RunDetailView::forRun($run);

        $this->assertSame(['approval-stage', 'approvalMatches'], $detail['declared_queries']);
        $this->assertCount(2, $detail['declared_query_targets']);
        $this->assertSame('approval-stage', $detail['declared_query_targets'][0]['name']);
        $this->assertTrue($detail['declared_query_targets'][0]['has_contract']);
        $this->assertSame([], $detail['declared_query_targets'][0]['parameters']);
        $this->assertSame('approvalMatches', $detail['declared_query_targets'][1]['name']);
        $this->assertTrue($detail['declared_query_targets'][1]['has_contract']);
        $this->assertSame('stage', $detail['declared_query_targets'][1]['parameters'][0]['name']);
        $this->assertSame('string', $detail['declared_query_targets'][1]['parameters'][0]['type']);
        $this->assertTrue($detail['can_query']);
        $this->assertNull($detail['query_blocked_reason']);
        $this->assertSame(['approved-by', 'rejected-by'], $detail['declared_signals']);
        $this->assertCount(2, $detail['declared_signal_targets']);
        $this->assertSame('approved-by', $detail['declared_signal_targets'][0]['name']);
        $this->assertTrue($detail['declared_signal_targets'][0]['has_contract']);
        $this->assertSame('actor', $detail['declared_signal_targets'][0]['parameters'][0]['name']);
        $this->assertSame('string', $detail['declared_signal_targets'][0]['parameters'][0]['type']);
        $this->assertSame('rejected-by', $detail['declared_signal_targets'][1]['name']);
        $this->assertFalse($detail['declared_signal_targets'][1]['has_contract']);
        $this->assertSame([], $detail['declared_signal_targets'][1]['parameters']);
        $this->assertSame(['mark-approved'], $detail['declared_updates']);
        $this->assertCount(1, $detail['declared_update_targets']);
        $this->assertSame('mark-approved', $detail['declared_update_targets'][0]['name']);
        $this->assertTrue($detail['declared_update_targets'][0]['has_contract']);
        $this->assertSame('approved', $detail['declared_update_targets'][0]['parameters'][0]['name']);
        $this->assertSame('bool', $detail['declared_update_targets'][0]['parameters'][0]['type']);
        $this->assertFalse($detail['declared_contract_backfill_needed']);
        $this->assertFalse($detail['declared_contract_backfill_available']);
    }

    public function testRunDetailViewLeavesPartialCommandContractSnapshotsReadOnly(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $workflow = WorkflowStub::make(TestCommandTargetWorkflow::class, 'detail-command-targets-partial');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $payload = $started->payload;
        $payload['declared_query_contracts'] = [
            [
                'name' => 'approval-stage',
                'parameters' => [],
            ],
        ];

        $started->forceFill([
            'payload' => $payload,
        ])->save();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('live_definition', $detail['declared_contract_source']);
        $this->assertTrue($detail['declared_contract_backfill_needed']);
        $this->assertTrue($detail['declared_contract_backfill_available']);
        $this->assertCount(2, $detail['declared_query_targets']);
        $this->assertSame('approval-stage', $detail['declared_query_targets'][0]['name']);
        $this->assertSame('approvalMatches', $detail['declared_query_targets'][1]['name']);
        $this->assertTrue($detail['declared_query_targets'][1]['has_contract']);

        $started->refresh();

        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('approved-by', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);
        $this->assertCount(1, $started->payload['declared_query_contracts'] ?? []);
        $this->assertSame('approval-stage', $started->payload['declared_query_contracts'][0]['name'] ?? null);
    }

    public function testRunDetailViewBlocksQueryAndUpdateWhenDurableTargetsExistButDefinitionIsUnavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $workflow = WorkflowStub::make(TestCommandTargetWorkflow::class, 'detail-definition-unavailable');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\CommandTargetWorkflow',
            'workflow_type' => 'missing-command-target-workflow',
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertFalse($detail['declared_contract_backfill_needed']);
        $this->assertFalse($detail['declared_contract_backfill_available']);
        $this->assertSame(['approval-stage', 'approvalMatches'], $detail['declared_queries']);
        $this->assertFalse($detail['can_query']);
        $this->assertSame('workflow_definition_unavailable', $detail['query_blocked_reason']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('workflow_definition_unavailable', $detail['update_blocked_reason']);
    }

    public function testRunDetailViewReturnsEmptyNormalizedTargetsWhenCommandContractIsUnavailable(): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'detail-command-contract-unavailable',
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCONTRACTUNAV1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'workflow.command-contract',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame([], $detail['declared_queries']);
        $this->assertSame([], $detail['declared_query_contracts']);
        $this->assertSame([], $detail['declared_query_targets']);
        $this->assertSame([], $detail['declared_signals']);
        $this->assertSame([], $detail['declared_signal_contracts']);
        $this->assertSame([], $detail['declared_signal_targets']);
        $this->assertSame([], $detail['declared_updates']);
        $this->assertSame([], $detail['declared_update_contracts']);
        $this->assertSame([], $detail['declared_update_targets']);
        $this->assertSame('unavailable', $detail['declared_contract_source']);
        $this->assertFalse($detail['declared_contract_backfill_needed']);
        $this->assertFalse($detail['declared_contract_backfill_available']);
    }

    public function testRunDetailViewIncludesCommandsActivitiesAndTimelineForCompletedSignalRun(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal-complete');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);

        $this->assertSame('completed', $detail['status']);
        $this->assertSame('completed', $detail['status_bucket']);
        $this->assertTrue($detail['is_terminal']);
        $this->assertSame('completed', $detail['closed_reason']);
        $this->assertFalse($detail['can_issue_terminal_commands']);
        $this->assertFalse($detail['can_cancel']);
        $this->assertSame('run_closed', $detail['cancel_blocked_reason']);
        $this->assertFalse($detail['can_terminate']);
        $this->assertSame('run_closed', $detail['terminate_blocked_reason']);
        $this->assertFalse($detail['can_signal']);
        $this->assertSame('run_closed', $detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('run_closed', $detail['update_blocked_reason']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('run_closed', $detail['repair_blocked_reason']);
        $this->assertSame('Run is closed.', $detail['read_only_reason']);
        $this->assertSame(0, $detail['exception_count']);
        $this->assertSame(0, $detail['exceptions_count']);
        $this->assertCount(2, $detail['commands']);
        $this->assertSame(1, $detail['commands'][0]['sequence']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame(config('workflows.serializer'), $detail['commands'][0]['payload_codec']);
        $this->assertTrue($detail['commands'][0]['payload_available']);
        $this->assertSame(serialize([]), $detail['commands'][0]['payload']);
        $this->assertSame(2, $detail['commands'][1]['sequence']);
        $this->assertSame('signal', $detail['commands'][1]['type']);
        $this->assertSame('name-provided', $detail['commands'][1]['target_name']);
        $this->assertSame(config('workflows.serializer'), $detail['commands'][1]['payload_codec']);
        $this->assertTrue($detail['commands'][1]['payload_available']);
        $this->assertSame(serialize([
            'name' => 'name-provided',
            'arguments' => ['Taylor'],
            'validation_errors' => [],
        ]), $detail['commands'][1]['payload']);
        $this->assertSame('signal_received', $detail['commands'][1]['outcome']);
        $this->assertCount(1, $detail['activities']);
        $this->assertSame('completed', $detail['activities'][0]['status']);
        $this->assertSame(1, $detail['activities'][0]['attempt_count']);
        $this->assertSame($execution->current_attempt_id, $detail['activities'][0]['attempt_id']);
        $this->assertCount(1, $detail['activities'][0]['attempts']);
        $this->assertSame($execution->current_attempt_id, $detail['activities'][0]['attempts'][0]['id']);
        $this->assertSame(1, $detail['activities'][0]['attempts'][0]['attempt_number']);
        $this->assertSame('completed', $detail['activities'][0]['attempts'][0]['status']);
        $this->assertNotNull($detail['activities'][0]['attempts'][0]['closed_at']);
        $this->assertNotNull($detail['activities'][0]['created_at']);
        $this->assertSame('Hello, Taylor!', unserialize($detail['activities'][0]['result']));
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');
        $this->assertSame('resolved', $signalWait['status']);
        $this->assertSame('applied', $signalWait['source_status']);
        $this->assertSame('Signal name-provided received.', $signalWait['summary']);
        $this->assertSame(2, $signalWait['command_sequence']);
        $this->assertSame('accepted', $signalWait['command_status']);
        $this->assertSame('signal_received', $signalWait['command_outcome']);
        $activityWait = $this->findWait($detail['waits'], 'activity');
        $this->assertSame('resolved', $activityWait['status']);
        $this->assertSame('completed', $activityWait['source_status']);
        $this->assertSame(
            'Activity ' . \Tests\Fixtures\V2\TestGreetingActivity::class . ' completed.',
            $activityWait['summary']
        );
        $this->assertFalse($activityWait['task_backed']);
        $this->assertNotNull($activityWait['task_id']);
        $this->assertSame('activity', $activityWait['task_type']);
        $this->assertSame('completed', $activityWait['task_status']);
        $activityTask = $this->findTask($detail['tasks'], 'activity');
        $this->assertSame('completed', $activityTask['status']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $activityTask['activity_type']);
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($detail['timeline'], 'type'));
        $this->assertSame(
            [1, 1, null, 2, 2, null, null, null, null],
            array_column($detail['timeline'], 'command_sequence')
        );
    }

    public function testRunDetailViewKeepsActivityDetailWhenActivityRowDrifts(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-activity-row-drift');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $execution->forceFill([
            'activity_class' => 'MutatedActivityClass',
            'activity_type' => 'mutated.activity',
            'status' => 'failed',
            'arguments' => Serializer::serialize(['mutated']),
            'result' => Serializer::serialize('Mutated'),
        ])->save();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run);

        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['class']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['type']);
        $this->assertSame('completed', $detail['activities'][0]['status']);
        $this->assertSame(['Taylor'], unserialize($detail['activities'][0]['arguments']));
        $this->assertSame('Hello, Taylor!', unserialize($detail['activities'][0]['result']));
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['logs'][0]['class']);
        $this->assertSame('Hello, Taylor!', unserialize($detail['logs'][0]['result']));
        $this->assertSame('Activity', $detail['chartData'][1]['type']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['chartData'][1]['x']);
    }

    public function testRunDetailViewFallsBackToTypedActivityHistoryWhenActivityRowIsMissing(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-activity-row-missing');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run);

        $this->assertCount(1, $detail['activities']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['class']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['type']);
        $this->assertSame('completed', $detail['activities'][0]['status']);
        $this->assertSame(['Taylor'], unserialize($detail['activities'][0]['arguments']));
        $this->assertSame('Hello, Taylor!', unserialize($detail['activities'][0]['result']));
        $this->assertCount(1, $detail['logs']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['logs'][0]['class']);
        $this->assertSame('Hello, Taylor!', unserialize($detail['logs'][0]['result']));
        $this->assertCount(2, $detail['chartData']);
        $this->assertSame('Activity', $detail['chartData'][1]['type']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['chartData'][1]['x']);
    }

    public function testRunDetailViewKeepsSignalWaitCommandMetadataWhenCommandRowsDrift(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal-history-snapshot');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowCommand $signalCommand */
        $signalCommand = WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->where('command_type', 'signal')
            ->firstOrFail();

        $originalCommandSequence = $signalCommand->command_sequence;
        $signalCommand->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run);
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');

        $this->assertSame('resolved', $signalWait['status']);
        $this->assertSame('applied', $signalWait['source_status']);
        $this->assertSame($originalCommandSequence, $signalWait['command_sequence']);
        $this->assertSame('accepted', $signalWait['command_status']);
        $this->assertSame('signal_received', $signalWait['command_outcome']);
    }

    public function testRunDetailViewMarksReceivedSignalWithoutWorkflowTaskAsRepairNeeded(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal-repair');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $signal = $workflow->signal('name-provided', 'Taylor');

        $this->assertSame('signal_received', $signal->outcome());

        /** @var WorkflowSignal $signalRecord */
        $signalRecord = WorkflowSignal::query()
            ->where('workflow_command_id', $signal->commandId())
            ->sole();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');

        $this->assertSame('signal', $detail['wait_kind']);
        $this->assertSame('Waiting to apply signal name-provided', $detail['wait_reason']);
        $this->assertSame('signal-application:' . $signalRecord->id, $detail['open_wait_id']);
        $this->assertSame('workflow_signal', $detail['resume_source_kind']);
        $this->assertSame($signalRecord->id, $detail['resume_source_id']);
        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame(
            'Accepted signal name-provided is received without an open workflow task.',
            $detail['liveness_reason']
        );
        $this->assertTrue($detail['can_repair']);
        $this->assertSame('resolved', $signalWait['status']);
        $this->assertSame('received', $signalWait['source_status']);
        $this->assertSame('Signal name-provided received.', $signalWait['summary']);
        $this->assertFalse($signalWait['task_backed']);
        $this->assertSame('signal', $signalWait['resume_source_kind']);
        $this->assertSame($signal->commandId(), $signalWait['resume_source_id']);
        $this->assertSame(2, $signalWait['command_sequence']);
        $this->assertSame('accepted', $signalWait['command_status']);
        $this->assertSame('signal_received', $signalWait['command_outcome']);
        $this->assertNull($this->findOpenTaskOrNull($detail['tasks'], 'workflow'));

        $missingTask = $this->findTask($detail['tasks'], 'workflow');
        $this->assertTrue($missingTask['task_missing']);
        $this->assertTrue($missingTask['synthetic']);
        $this->assertSame('missing', $missingTask['transport_state']);
        $this->assertSame('missing', $missingTask['status']);
        $this->assertSame('signal', $missingTask['workflow_wait_kind']);
        $this->assertSame('signal-application:' . $signalRecord->id, $missingTask['workflow_open_wait_id']);
        $this->assertSame('workflow_signal', $missingTask['workflow_resume_source_kind']);
        $this->assertSame($signalRecord->id, $missingTask['workflow_resume_source_id']);
        $this->assertSame($signalRecord->id, $missingTask['workflow_signal_id']);
        $this->assertSame($signal->commandId(), $missingTask['workflow_command_id']);
    }

    public function testRunDetailViewDoesNotTreatUnrelatedWorkflowTaskAsAcceptedUpdateTransport(): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'detail-update-unrelated-task',
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Update->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [true],
                'validation_errors' => [],
            ]),
            'accepted_at' => now()
                ->subSeconds(20),
        ]);

        $update = WorkflowUpdate::create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'status' => UpdateStatus::Accepted->value,
            'command_sequence' => $command->command_sequence,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([true]),
            'accepted_at' => $command->accepted_at,
        ]);

        $unrelatedTask = WorkflowTask::create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(10),
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:signal-transport',
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => 'signal-transport',
                'workflow_signal_id' => 'signal-transport',
                'workflow_command_id' => 'signal-command',
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $updateWait = $this->findWait($detail['waits'], 'update', 'mark-approved');
        $openWorkflowTask = $this->findOpenTaskOrNull($detail['tasks'], 'workflow');
        $missingUpdateTask = collect($detail['tasks'])
            ->first(static fn (array $task): bool => ($task['task_missing'] ?? false) === true
                && ($task['workflow_wait_kind'] ?? null) === 'update');

        $this->assertSame('update', $detail['wait_kind']);
        $this->assertSame('Waiting for update mark-approved', $detail['wait_reason']);
        $this->assertSame('update:' . $update->id, $detail['open_wait_id']);
        $this->assertSame('workflow_update', $detail['resume_source_kind']);
        $this->assertSame($update->id, $detail['resume_source_id']);
        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame(
            'Accepted update mark-approved is open without an open workflow task.',
            $detail['liveness_reason']
        );
        $this->assertNull($detail['next_task_id']);
        $this->assertTrue($detail['can_repair']);
        $this->assertFalse($updateWait['task_backed']);
        $this->assertNull($updateWait['task_id']);
        $this->assertIsArray($openWorkflowTask);
        $this->assertSame($unrelatedTask->id, $openWorkflowTask['id']);
        $this->assertSame('signal', $openWorkflowTask['workflow_wait_kind']);
        $this->assertIsArray($missingUpdateTask);
        $this->assertSame('missing', $missingUpdateTask['transport_state']);
        $this->assertSame('update:' . $update->id, $missingUpdateTask['workflow_open_wait_id']);
        $this->assertSame('workflow_update', $missingUpdateTask['workflow_resume_source_kind']);
        $this->assertSame($update->id, $missingUpdateTask['workflow_update_id']);
        $this->assertSame($command->id, $missingUpdateTask['workflow_command_id']);
    }

    public function testAcceptedUpdateAnnotatesReusedReadyWorkflowTask(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestCommandTargetWorkflow::class, 'detail-update-reused-ready-task');
        $workflow->start();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([], $task->payload);

        $result = $workflow->submitUpdate('mark-approved', true);

        $this->assertSame('accepted', $result->status());
        $this->assertIsString($result->updateId());

        $task->refresh();

        $this->assertSame([
            'workflow_wait_kind' => 'update',
            'open_wait_id' => 'update:' . $result->updateId(),
            'resume_source_kind' => 'workflow_update',
            'resume_source_id' => $result->updateId(),
            'workflow_update_id' => $result->updateId(),
            'workflow_command_id' => $result->commandId(),
        ], $task->payload);
    }

    public function testRunDetailViewKeepsTypedFailureCodeAndPropertiesWhenFailureRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedFailureWorkflow::class, 'detail-history-failure');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $execution->forceFill([
            'result' => Serializer::serialize('corrupted-result'),
            'exception' => null,
        ])->save();

        $failure->forceFill([
            'exception_class' => \RuntimeException::class,
            'message' => 'corrupted failure row',
            'file' => __FILE__,
            'line' => 999,
        ])->save();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $exception = unserialize($detail['exceptions'][0]['exception']);
        $properties = collect($exception['properties'] ?? [])->keyBy('name');

        $this->assertSame(\Tests\Fixtures\V2\TestReplayedDomainException::class, $exception['__constructor']);
        $this->assertSame('Order order-123 rejected via api', $exception['message']);
        $this->assertSame(422, $exception['code']);
        $this->assertSame('order-123', $properties->get('orderId')['value'] ?? null);
        $this->assertSame('api', $properties->get('channel')['value'] ?? null);
        $this->assertNotEmpty($exception['trace']);
    }

    public function testRunDetailViewKeepsTypedFailureCountAndPayloadWhenFailureRowsAreMissing(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedFailureWorkflow::class, 'detail-history-failure-missing');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $failureId = $failure->id;
        $failure->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $exception = unserialize($detail['exceptions'][0]['exception']);
        $properties = collect($exception['properties'] ?? [])->keyBy('name');

        $this->assertSame(1, $detail['exception_count']);
        $this->assertSame(1, $detail['exceptions_count']);
        $this->assertCount(1, $detail['exceptions']);
        $this->assertSame($failureId, $detail['exceptions'][0]['id']);
        $this->assertSame('typed_history', $detail['exceptions'][0]['history_authority']);
        $this->assertFalse($detail['exceptions'][0]['diagnostic_only']);
        $this->assertIsString($detail['exceptions'][0]['code']);
        $this->assertNotSame('', $detail['exceptions'][0]['code']);
        $this->assertSame(1, $run->fresh(['summary'])->summary?->exception_count);
        $this->assertSame(\Tests\Fixtures\V2\TestReplayedDomainException::class, $exception['__constructor']);
        $this->assertSame('Order order-123 rejected via api', $exception['message']);
        $this->assertSame(422, $exception['code']);
        $this->assertSame('order-123', $properties->get('orderId')['value'] ?? null);
        $this->assertSame('api', $properties->get('channel')['value'] ?? null);
        $this->assertNotEmpty($exception['trace']);

        $handledTimelineEntry = collect($detail['timeline'])
            ->firstWhere('type', 'FailureHandled');

        $this->assertIsArray($handledTimelineEntry);
        $this->assertSame('failure', $handledTimelineEntry['kind']);
        $this->assertSame('workflow_failure', $handledTimelineEntry['source_kind']);
        $this->assertSame($failureId, $handledTimelineEntry['source_id']);
        $this->assertSame($failureId, $handledTimelineEntry['failure_id']);
        $this->assertSame('Handled failure: Order order-123 rejected via api.', $handledTimelineEntry['summary']);
        $this->assertTrue($handledTimelineEntry['failure']['handled'] ?? false);
    }

    public function testRunDetailViewDistinguishesRepeatedSameNamedSignalWaits(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'detail-signal-order');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $firstSignal = $workflow->signal('message', 'first');

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertSame('waiting', $workflow->status());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $signalWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal',
        ));

        usort(
            $signalWaits,
            static fn (array $left, array $right): int => ($left['sequence'] ?? 0) <=> ($right['sequence'] ?? 0)
        );

        $this->assertCount(2, $signalWaits);
        $this->assertSame([1, 2], array_column($signalWaits, 'sequence'));
        $this->assertSame(['resolved', 'open'], array_column($signalWaits, 'status'));
        $this->assertSame(['applied', 'waiting'], array_column($signalWaits, 'source_status'));
        $this->assertSame(['message', 'message'], array_column($signalWaits, 'target_name'));
        $this->assertSame([$firstSignal->commandId(), null], array_column($signalWaits, 'command_id'));
        $this->assertNotSame($signalWaits[0]['signal_wait_id'], $signalWaits[1]['signal_wait_id']);
        $this->assertIsString($signalWaits[0]['signal_wait_id']);
        $this->assertIsString($signalWaits[1]['signal_wait_id']);
    }

    public function testRunDetailViewPreservesWaitIdsForBufferedSameNamedSignals(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'detail-buffered-signal-order');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $firstSignal = $workflow->signal('message', 'first');
        $secondSignal = $workflow->signal('message', 'second');

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $signalWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal',
        ));

        usort(
            $signalWaits,
            static fn (array $left, array $right): int => ($left['sequence'] ?? 0) <=> ($right['sequence'] ?? 0)
        );

        $this->assertCount(2, $signalWaits);
        $this->assertSame([1, 2], array_column($signalWaits, 'sequence'));
        $this->assertSame(['resolved', 'resolved'], array_column($signalWaits, 'status'));
        $this->assertSame(['applied', 'applied'], array_column($signalWaits, 'source_status'));
        $this->assertSame(
            [$firstSignal->commandId(), $secondSignal->commandId()],
            array_column($signalWaits, 'command_id')
        );
        $this->assertSame([2, 3], array_column($signalWaits, 'command_sequence'));
        $this->assertNotSame($signalWaits[0]['signal_wait_id'], $signalWaits[1]['signal_wait_id']);
        $this->assertIsString($signalWaits[0]['signal_wait_id']);
        $this->assertIsString($signalWaits[1]['signal_wait_id']);
    }

    public function testRunDetailViewIncludesCurrentRunPointerForHistoricalRun(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-historical-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()
                ->subMinutes(10),
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(9),
            'last_progress_at' => now()
                ->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($historicalRun->fresh(['summary', 'instance.currentRun.summary']));

        $this->assertSame($historicalRun->id, $detail['selected_run_id']);
        $this->assertSame($historicalRun->id, $detail['run_id']);
        $this->assertFalse($detail['is_current_run']);
        $this->assertSame($currentRun->id, $detail['current_run_id']);
        $this->assertSame('waiting', $detail['current_run_status']);
        $this->assertSame('running', $detail['current_run_status_bucket']);
        $this->assertFalse($detail['can_issue_terminal_commands']);
        $this->assertFalse($detail['can_cancel']);
        $this->assertSame('selected_run_not_current', $detail['cancel_blocked_reason']);
        $this->assertFalse($detail['can_terminate']);
        $this->assertSame('selected_run_not_current', $detail['terminate_blocked_reason']);
        $this->assertFalse($detail['can_signal']);
        $this->assertSame('selected_run_not_current', $detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('selected_run_not_current', $detail['update_blocked_reason']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('selected_run_not_current', $detail['repair_blocked_reason']);
        $this->assertSame(
            'Selected run is historical. Issue commands against the current active run.',
            $detail['read_only_reason'],
        );
    }

    public function testRunDetailViewResolvesCurrentRunPointerWhenCurrentRunColumnIsMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-historical-instance-pointer-drift',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()
                ->subMinutes(10),
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(9),
            'last_progress_at' => now()
                ->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => null,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($historicalRun->fresh(['summary', 'instance.runs.summary']));

        $this->assertSame($historicalRun->id, $detail['selected_run_id']);
        $this->assertSame($historicalRun->id, $detail['run_id']);
        $this->assertFalse($detail['is_current_run']);
        $this->assertSame($currentRun->id, $detail['current_run_id']);
        $this->assertSame('run_order_fallback', $detail['current_run_source']);
        $this->assertSame('waiting', $detail['current_run_status']);
        $this->assertSame('running', $detail['current_run_status_bucket']);
        $this->assertFalse($detail['can_cancel']);
        $this->assertSame('selected_run_not_current', $detail['cancel_blocked_reason']);
        $this->assertFalse($detail['can_terminate']);
        $this->assertSame('selected_run_not_current', $detail['terminate_blocked_reason']);
        $this->assertFalse($detail['can_signal']);
        $this->assertSame('selected_run_not_current', $detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('selected_run_not_current', $detail['update_blocked_reason']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('selected_run_not_current', $detail['repair_blocked_reason']);
        $this->assertSame(
            'Selected run is historical. Issue commands against the current active run.',
            $detail['read_only_reason'],
        );
        $this->assertTrue($instance->fresh()->current_run_id === null);
    }

    public function testRunDetailViewPrefersContinueAsNewLineageOverRunOrderingForCurrentRun(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'detail-continued-current-run');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'detail-continued-current-run')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $currentRun */
        $currentRun = $runs[1];

        WorkflowRun::query()->create([
            'workflow_instance_id' => $historicalRun->workflow_instance_id,
            'run_number' => $currentRun->run_number + 1,
            'workflow_class' => $currentRun->workflow_class,
            'workflow_type' => $currentRun->workflow_type,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([999, 1000]),
            'connection' => $currentRun->connection,
            'queue' => $currentRun->queue,
            'started_at' => now()
                ->addMinute(),
            'last_progress_at' => now()
                ->addMinute(),
        ]);

        WorkflowInstance::query()
            ->findOrFail($historicalRun->workflow_instance_id)
            ->forceFill([
                'current_run_id' => null,
            ])
            ->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($historicalRun->fresh(['summary', 'instance.runs.summary']));

        $this->assertSame($currentRun->id, $detail['current_run_id']);
        $this->assertSame('continue_as_new_lineage', $detail['current_run_source']);
        $this->assertSame('completed', $detail['current_run_status']);
        $this->assertSame('completed', $detail['current_run_status_bucket']);
    }

    public function testRunDetailViewIncludesContinueAsNewLineage(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'detail-continued');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'detail-continued')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $currentRun */
        $currentRun = $runs[1];

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $historicalDetail = RunDetailView::forRun(
            $historicalRun->fresh(['summary', 'instance.currentRun.summary'])
        );
        $currentDetail = RunDetailView::forRun($currentRun->fresh(['summary', 'instance.currentRun.summary']));

        $this->assertSame('completed', $historicalDetail['status']);
        $this->assertSame('continued', $historicalDetail['closed_reason']);
        $this->assertFalse($historicalDetail['is_current_run']);
        $this->assertSame($currentRun->id, $historicalDetail['current_run_id']);
        $this->assertSame('workflow_run_lineage_entries', $historicalDetail['lineage_projection_source']);
        $this->assertCount(0, $historicalDetail['parents']);
        $this->assertCount(1, $historicalDetail['continuedWorkflows']);
        $this->assertSame('continue_as_new', $historicalDetail['continuedWorkflows'][0]['link_type']);
        $this->assertSame($currentRun->id, $historicalDetail['continuedWorkflows'][0]['child_workflow_run_id']);
        $this->assertSame('completed', $historicalDetail['continuedWorkflows'][0]['status']);
        $this->assertSame('completed', $historicalDetail['continuedWorkflows'][0]['status_bucket']);
        $this->assertSame('WorkflowContinuedAsNew', $historicalDetail['timeline'][5]['type']);

        $this->assertTrue($currentDetail['is_current_run']);
        $this->assertSame($currentRun->id, $currentDetail['current_run_id']);
        $this->assertSame('workflow_run_lineage_entries', $currentDetail['lineage_projection_source']);
        $this->assertCount(1, $currentDetail['parents']);
        $this->assertCount(0, $currentDetail['continuedWorkflows']);
        $this->assertCount(1, $currentDetail['commands']);
        $this->assertSame('start', $currentDetail['commands'][0]['type']);
        $this->assertSame('workflow', $currentDetail['commands'][0]['source']);
        $this->assertSame('Workflow', $currentDetail['commands'][0]['caller_label']);
        $this->assertSame(['workflow'], array_keys($currentDetail['commands'][0]['context']));
        $this->assertSame($historicalRun->id, $currentDetail['commands'][0]['context']['workflow']['parent_run_id']);
        $this->assertSame(2, $currentDetail['commands'][0]['context']['workflow']['sequence']);
        $this->assertSame('continue_as_new', $currentDetail['parents'][0]['link_type']);
        $this->assertSame($historicalRun->id, $currentDetail['parents'][0]['parent_workflow_run_id']);
        $this->assertSame('completed', $currentDetail['parents'][0]['status']);
        $this->assertSame('completed', $currentDetail['parents'][0]['status_bucket']);
        $this->assertSame('completed', $currentDetail['status']);
        $this->assertSame('completed', $currentDetail['closed_reason']);
        $this->assertSame('StartAccepted', $currentDetail['timeline'][0]['type']);
        $this->assertSame(1, $currentDetail['timeline'][0]['command_sequence']);

        WorkflowRunLineageEntry::query()
            ->whereIn('workflow_run_id', [$historicalRun->id, $currentRun->id])
            ->delete();

        $rebuiltHistoricalDetail = RunDetailView::forRun(
            $historicalRun->fresh(['summary', 'instance.currentRun.summary'])
        );
        $rebuiltCurrentDetail = RunDetailView::forRun($currentRun->fresh(['summary', 'instance.currentRun.summary']));

        $this->assertSame(
            'workflow_run_lineage_entries_rebuilt',
            $rebuiltHistoricalDetail['lineage_projection_source']
        );
        $this->assertSame('workflow_run_lineage_entries_rebuilt', $rebuiltCurrentDetail['lineage_projection_source']);
        $this->assertSame($currentRun->id, $rebuiltHistoricalDetail['continuedWorkflows'][0]['child_workflow_run_id']);
        $this->assertSame($historicalRun->id, $rebuiltCurrentDetail['parents'][0]['parent_workflow_run_id']);
    }

    public function testRunDetailViewOmitsRawRequestContextForWebhookCommands(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-command-context',
            'workflow_class' => TestSignalWorkflow::class,
            'workflow_type' => 'workflow.test-signal',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => '01JDETAILCOMMANDCONTEXT0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestSignalWorkflow::class,
            'workflow_type' => 'workflow.test-signal',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        WorkflowCommand::query()->create([
            'id' => '01JDETAILCOMMANDCTXCOMMAND01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => TestSignalWorkflow::class,
            'workflow_type' => 'workflow.test-signal',
            'context' => [
                'caller' => [
                    'type' => 'webhook',
                    'label' => 'Webhook',
                ],
                'auth' => [
                    'status' => 'authorized',
                    'method' => 'token',
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/webhooks/instances/detail-command-context/signals/name-provided',
                    'route_name' => 'workflows.v2.signal',
                    'ip' => '127.0.0.1',
                    'user_agent' => 'Workflow Test Agent',
                    'request_id' => 'req-123',
                    'correlation_id' => 'corr-123',
                    'fingerprint' => 'sha256:detail-command-context',
                ],
            ],
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'name-provided',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()
                ->subSeconds(30),
            'created_at' => now()
                ->subSeconds(30),
            'updated_at' => now()
                ->subSeconds(30),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertCount(1, $detail['commands']);
        $this->assertSame([], $detail['commands'][0]['context']);
        $this->assertSame('Webhook', $detail['commands'][0]['caller_label']);
        $this->assertSame('authorized', $detail['commands'][0]['auth_status']);
        $this->assertSame('token', $detail['commands'][0]['auth_method']);
        $this->assertSame('POST', $detail['commands'][0]['request_method']);
        $this->assertSame(
            '/webhooks/instances/detail-command-context/signals/name-provided',
            $detail['commands'][0]['request_path'],
        );
        $this->assertSame('workflows.v2.signal', $detail['commands'][0]['request_route_name']);
        $this->assertSame('sha256:detail-command-context', $detail['commands'][0]['request_fingerprint']);
        $this->assertSame('req-123', $detail['commands'][0]['request_id']);
        $this->assertSame('corr-123', $detail['commands'][0]['correlation_id']);
    }

    public function testRunDetailViewIncludesInstanceRunNavigation(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'detail-run-navigation');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'detail-run-navigation')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $currentRun */
        $currentRun = $runs[1];

        $detail = RunDetailView::forRun($historicalRun->fresh(['summary', 'instance.currentRun.summary']));

        $this->assertCount(2, $detail['run_navigation']);
        $this->assertSame(
            [
                [
                    'instance_id' => 'detail-run-navigation',
                    'run_id' => $historicalRun->id,
                    'run_number' => 1,
                    'status' => 'completed',
                    'status_bucket' => 'completed',
                    'closed_reason' => 'continued',
                    'is_current_run' => false,
                    'is_selected_run' => true,
                ],
                [
                    'instance_id' => 'detail-run-navigation',
                    'run_id' => $currentRun->id,
                    'run_number' => 2,
                    'status' => 'completed',
                    'status_bucket' => 'completed',
                    'closed_reason' => 'completed',
                    'is_current_run' => true,
                    'is_selected_run' => false,
                ],
            ],
            array_map(
                static fn (array $entry): array => [
                    'instance_id' => $entry['instance_id'],
                    'run_id' => $entry['run_id'],
                    'run_number' => $entry['run_number'],
                    'status' => $entry['status'],
                    'status_bucket' => $entry['status_bucket'],
                    'closed_reason' => $entry['closed_reason'],
                    'is_current_run' => $entry['is_current_run'],
                    'is_selected_run' => $entry['is_selected_run'],
                ],
                $detail['run_navigation'],
            ),
        );
    }

    public function testRunDetailViewIncludesChildWaitAndLineageForParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentWaitingOnChildWorkflow::class, 'detail-child-parent');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'child');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $runId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $detail = RunDetailView::forRun($run);
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('waiting', $detail['status']);
        $this->assertSame('child', $detail['wait_kind']);
        $this->assertSame('waiting_for_child', $detail['liveness_state']);
        $this->assertSame('open', $childWait['status']);
        $this->assertSame('waiting', $childWait['source_status']);
        $this->assertStringStartsWith('Waiting for child workflow ', $childWait['summary']);
        $this->assertFalse($childWait['task_backed']);
        $this->assertFalse($childWait['external_only']);
        $this->assertSame('child_workflow_run', $childWait['resume_source_kind']);
        $this->assertNotNull($childWait['resume_source_id']);
        $this->assertSame($link->id, $childWait['child_call_id']);
        $this->assertSame('child:' . $link->id, $detail['open_wait_id']);
        $this->assertCount(0, $detail['parents']);
        $this->assertCount(1, $detail['continuedWorkflows']);
        $this->assertSame('child_workflow', $detail['continuedWorkflows'][0]['link_type']);
        $this->assertSame($link->id, $detail['continuedWorkflows'][0]['child_call_id']);
        $this->assertSame(1, $detail['continuedWorkflows'][0]['sequence']);
        $this->assertSame('waiting', $detail['continuedWorkflows'][0]['status']);
        $this->assertSame(TestTimerWorkflow::class, $detail['continuedWorkflows'][0]['workflow_type']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('repair_not_needed', $detail['repair_blocked_reason']);
    }

    public function testRunDetailViewKeepsOpenChildWaitFromParentHistoryWhenChildRowDriftsTerminal(): void
    {
        $parentInstance = WorkflowInstance::create([
            'id' => 'detail-child-authority-parent',
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'detail-child-authority-child',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTRUNDETAILCHILDAUTH01',
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(4),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $childRun = WorkflowRun::create([
            'id' => '01JTESTRUNDETAILCHILDAUTH02',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize([
                'child' => 'corrupted-terminal-row',
            ]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(3),
            'closed_at' => now()
                ->subSeconds(30),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $parentInstance->update([
            'current_run_id' => $run->id,
        ]);
        $childInstance->update([
            'current_run_id' => $childRun->id,
        ]);

        $link = WorkflowLink::create([
            'id' => '01JTESTRUNDETAILCHILDLINK01',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()
                ->subSeconds(90),
            'updated_at' => now()
                ->subSeconds(90),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTRUNDETAILCHILDEVENT1',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $link->id,
                'child_call_id' => $link->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_type' => 'workflow.child',
                'child_workflow_class' => TestTimerWorkflow::class,
                'child_run_number' => 1,
            ],
            'recorded_at' => now()
                ->subSeconds(90),
            'created_at' => now()
                ->subSeconds(90),
            'updated_at' => now()
                ->subSeconds(90),
        ]);

        RunSummaryProjector::project($run->fresh());

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('child', $detail['wait_kind']);
        $this->assertSame('waiting_for_child', $detail['liveness_state']);
        $this->assertSame('child:' . $link->id, $detail['open_wait_id']);
        $this->assertSame('open', $childWait['status']);
        $this->assertSame('waiting', $childWait['source_status']);
        $this->assertSame($link->child_workflow_instance_id, $childWait['target_name']);
        $this->assertSame($childRun->id, $childWait['resume_source_id']);
    }

    public function testRunDetailViewKeepsResolvedChildWaitFromParentHistoryWhenChildRowDrifts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'detail-child-history');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $runId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $childRun->forceFill([
            'status' => RunStatus::Waiting,
            'closed_reason' => null,
            'closed_at' => null,
        ])->save();
        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run);
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('completed', $detail['status']);
        $this->assertSame('resolved', $childWait['status']);
        $this->assertSame('completed', $childWait['source_status']);
        $this->assertSame('Child workflow test-child-greeting-workflow completed.', $childWait['summary']);
        $this->assertSame($link->child_workflow_instance_id, $childWait['target_name']);
        $this->assertSame($link->child_workflow_run_id, $childWait['resume_source_id']);
        $this->assertSame('workflow_run_lineage_entries_rebuilt', $detail['lineage_projection_source']);
        $this->assertCount(1, $detail['continuedWorkflows']);
        $this->assertSame('child_workflow', $detail['continuedWorkflows'][0]['link_type']);
        $this->assertSame($link->id, $detail['continuedWorkflows'][0]['child_call_id']);
        $this->assertSame($childRun->workflow_type, $detail['continuedWorkflows'][0]['workflow_type']);
        $this->assertSame($childRun->workflow_class, $detail['continuedWorkflows'][0]['class']);
        $this->assertSame($childRun->run_number, $detail['continuedWorkflows'][0]['run_number']);
        $this->assertSame('completed', $detail['continuedWorkflows'][0]['status']);
        $this->assertSame('completed', $detail['continuedWorkflows'][0]['status_bucket']);
        $this->assertSame('completed', $detail['continuedWorkflows'][0]['closed_reason']);
    }

    public function testRunDetailViewKeepsCurrentContinuedChildFromHistoryWhenLinksDisappear(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $instanceId = 'detail-child-history';

        $workflow = WorkflowStub::make(TestParentWaitingOnContinuingChildWorkflow::class, $instanceId);
        $workflow->start(0, 1);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $deadline = microtime(true) + 10;

        while (WorkflowRun::query()
            ->where('workflow_type', 'test-continue-as-new-workflow')
            ->count() < 2) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the child workflow to continue as new.');
            }

            $this->runNextReadyTask();
        }

        /** @var WorkflowHistoryEvent $childStarted */
        $childStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunStarted')
            ->orderByDesc('sequence')
            ->firstOrFail();
        $childInstanceId = $childStarted->payload['child_workflow_instance_id'] ?? null;
        $childCallId = $childStarted->payload['child_call_id'] ?? null;

        $this->assertIsString($childInstanceId);
        $this->assertIsString($childCallId);

        /** @var WorkflowRun $currentChildRun */
        $currentChildRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childInstanceId)
            ->orderByDesc('run_number')
            ->firstOrFail();

        $detailWithLinks = RunDetailView::forRun(WorkflowRun::query()->findOrFail($parentRunId)->fresh(['summary']));
        $this->assertCount(1, $detailWithLinks['continuedWorkflows']);
        $this->assertSame($currentChildRun->id, $detailWithLinks['continuedWorkflows'][0]['child_workflow_run_id']);

        WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstanceId,
            'run_number' => $currentChildRun->run_number + 1,
            'workflow_class' => $currentChildRun->workflow_class,
            'workflow_type' => $currentChildRun->workflow_type,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([999, 1000]),
            'connection' => $currentChildRun->connection,
            'queue' => $currentChildRun->queue,
            'started_at' => now()
                ->addMinute(),
            'last_progress_at' => now()
                ->addMinute(),
        ]);

        WorkflowInstance::query()
            ->findOrFail($childInstanceId)
            ->forceFill([
                'current_run_id' => null,
            ])
            ->save();

        WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->delete();
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunStarted')
            ->get()
            ->each
            ->delete();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($parentRunId);
        RunSummaryProjector::project(
            $parentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($parentRun->fresh(['summary']));
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('child', $detail['wait_kind']);
        $this->assertSame('open', $childWait['status']);
        $this->assertSame($childInstanceId, $childWait['target_name']);
        $this->assertSame($currentChildRun->id, $childWait['resume_source_id']);
        $this->assertSame($childCallId, $childWait['child_call_id']);
        $this->assertSame('child:' . $childCallId, $detail['open_wait_id']);
        $this->assertCount(1, $detail['continuedWorkflows']);
        $this->assertSame('child_workflow', $detail['continuedWorkflows'][0]['link_type']);
        $this->assertSame($childCallId, $detail['continuedWorkflows'][0]['child_call_id']);
        $this->assertSame($currentChildRun->id, $detail['continuedWorkflows'][0]['child_workflow_run_id']);
        $this->assertSame($currentChildRun->status->value, $detail['continuedWorkflows'][0]['status']);
        $this->assertSame($currentChildRun->workflow_type, $detail['continuedWorkflows'][0]['workflow_type']);
    }

    public function testRunDetailViewCountsOpenParallelChildWaitsAndExposesGroupMetadata(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildWorkflow::class, 'detail-parallel-children');
        $workflow->start(60, 60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $childWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'child' && ($wait['status'] ?? null) === 'open',
        ));

        $this->assertSame('child', $detail['wait_kind']);
        $this->assertSame(2, $detail['open_wait_count']);
        $this->assertCount(2, $childWaits);
        $this->assertSame('parallel-children:1:2', $childWaits[0]['parallel_group_id']);
        $this->assertSame('parallel-children:1:2', $childWaits[1]['parallel_group_id']);
        $this->assertSame('child', $childWaits[0]['parallel_group_kind']);
        $this->assertSame('child', $childWaits[1]['parallel_group_kind']);
        $this->assertSame(2, $childWaits[0]['parallel_group_size']);
        $this->assertSame(2, $childWaits[1]['parallel_group_size']);
        $this->assertSame(0, $childWaits[0]['parallel_group_index']);
        $this->assertSame(1, $childWaits[1]['parallel_group_index']);
    }

    public function testRunDetailViewCountsOpenParallelActivityWaitsAndExposesGroupMetadata(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'detail-parallel-activities');
        $workflow->start('Taylor', 'Abigail');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $activityWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'activity' && ($wait['status'] ?? null) === 'open',
        ));

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame(2, $detail['open_wait_count']);
        $this->assertCount(2, $activityWaits);
        $this->assertSame('parallel-activities:1:2', $activityWaits[0]['parallel_group_id']);
        $this->assertSame('parallel-activities:1:2', $activityWaits[1]['parallel_group_id']);
        $this->assertSame('activity', $activityWaits[0]['parallel_group_kind']);
        $this->assertSame('activity', $activityWaits[1]['parallel_group_kind']);
        $this->assertSame(2, $activityWaits[0]['parallel_group_size']);
        $this->assertSame(2, $activityWaits[1]['parallel_group_size']);
        $this->assertSame(0, $activityWaits[0]['parallel_group_index']);
        $this->assertSame(1, $activityWaits[1]['parallel_group_index']);
    }

    public function testRunDetailViewExposesNestedParallelActivityPathsForOpenWaits(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'detail-nested-parallel-activities');
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $activityWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'activity' && ($wait['status'] ?? null) === 'open',
        ));

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame(3, $detail['open_wait_count']);
        $this->assertCount(3, $activityWaits);

        $this->assertSame('parallel-activities:1:3', $activityWaits[0]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 0,
            ],
        ], $activityWaits[0]['parallel_group_path']);

        $this->assertSame('parallel-activities:2:2', $activityWaits[1]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 1,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 0,
            ],
        ], $activityWaits[1]['parallel_group_path']);

        $this->assertSame('parallel-activities:2:2', $activityWaits[2]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 2,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 1,
            ],
        ], $activityWaits[2]['parallel_group_path']);
    }

    public function testRunDetailViewCountsOpenMixedBarrierWaitsAndExposesMixedGroupMetadata(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelWorkflow::class, 'detail-mixed-barrier');
        $workflow->start('Taylor', 60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);
        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $openWaits = array_values(array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['status'] ?? null) === 'open',
        ));

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame(2, $detail['open_wait_count']);
        $this->assertCount(2, $openWaits);
        $this->assertSame('parallel-calls:1:2', $openWaits[0]['parallel_group_id']);
        $this->assertSame('parallel-calls:1:2', $openWaits[1]['parallel_group_id']);
        $this->assertSame('mixed', $openWaits[0]['parallel_group_kind']);
        $this->assertSame('mixed', $openWaits[1]['parallel_group_kind']);
        $this->assertSame(2, $openWaits[0]['parallel_group_size']);
        $this->assertSame(2, $openWaits[1]['parallel_group_size']);
        $this->assertSame([0, 1], array_column($openWaits, 'parallel_group_index'));
        $this->assertSame(['activity', 'child'], array_column($openWaits, 'kind'));
    }

    public function testRunDetailViewIncludesTaskBackedTimerWaitForSelectedRun(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();
        $this->assertSame('timer', $workflow->refresh()->summary()?->wait_kind);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertSame('open', $timerWait['status']);
        $this->assertSame('pending', $timerWait['source_status']);
        $this->assertSame('Waiting for timer.', $timerWait['summary']);
        $this->assertTrue($timerWait['task_backed']);
        $this->assertFalse($timerWait['external_only']);
        $this->assertSame('timer', $timerWait['resume_source_kind']);
        $this->assertNotNull($timerWait['resume_source_id']);
        $this->assertNotNull($timerWait['task_id']);
        $this->assertSame('timer', $timerWait['task_type']);
        $this->assertSame('ready', $timerWait['task_status']);

        $timerTask = $this->findTask($detail['tasks'], 'timer');

        $this->assertTrue($timerTask['is_open']);
        $this->assertSame('ready', $timerTask['status']);
        $this->assertSame($timerWait['resume_source_id'], $timerTask['timer_id']);
        $this->assertSame(1, $timerTask['timer_sequence']);
        $this->assertNotNull($timerTask['timer_fire_at']);
    }

    public function testRunDetailViewKeepsTimerWaitAndTaskMetadataWhenTimerRowDrifts(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer-history');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();
        $this->assertSame('timer', $workflow->refresh()->summary()?->wait_kind);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $timerId = $timer->id;
        $deadlineAt = $timer->fire_at?->toJSON();

        $timer->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
        $timerWait = $this->findWait($detail['waits'], 'timer');
        $timerTask = $this->findTask($detail['tasks'], 'timer');

        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertSame('Waiting for timer', $detail['wait_reason']);
        $this->assertSame('timer_scheduled', $detail['liveness_state']);
        $this->assertSame('timer', $detail['resume_source_kind']);
        $this->assertSame($timerId, $detail['resume_source_id']);
        $this->assertSame($deadlineAt, $detail['wait_deadline_at']?->toJSON());
        $this->assertCount(1, $detail['timers']);
        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('pending', $detail['timers'][0]['status']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertSame('open', $timerWait['status']);
        $this->assertSame('pending', $timerWait['source_status']);
        $this->assertTrue($timerWait['task_backed']);
        $this->assertSame($timerId, $timerWait['resume_source_id']);
        $this->assertSame($deadlineAt, $timerWait['deadline_at']?->toJSON());
        $this->assertSame($timerId, $timerTask['timer_id']);
        $this->assertSame(1, $timerTask['timer_sequence']);
        $this->assertSame($deadlineAt, $timerTask['timer_fire_at']?->toJSON());
    }

    public function testRunDetailViewKeepsTypedTimerScheduledWhenMutableTimerRowFiresWithoutHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer-row-fired-without-history');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $timerId = $timer->id;
        $deadlineAt = $timer->fire_at?->toJSON();

        $timer->forceFill([
            'status' => TimerStatus::Fired->value,
            'fired_at' => now(),
        ])->save();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertSame('timer_scheduled', $detail['liveness_state']);
        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertSame($timerId, $detail['resume_source_id']);
        $this->assertCount(1, $detail['timers']);
        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('pending', $detail['timers'][0]['status']);
        $this->assertSame('pending', $detail['timers'][0]['source_status']);
        $this->assertSame('fired', $detail['timers'][0]['row_status']);
        $this->assertSame('typed_history', $detail['timers'][0]['history_authority']);
        $this->assertSame(['TimerScheduled'], $detail['timers'][0]['history_event_types']);
        $this->assertNull($detail['timers'][0]['history_unsupported_reason']);
        $this->assertNull($detail['timers'][0]['fired_at']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertSame('open', $timerWait['status']);
        $this->assertSame('pending', $timerWait['source_status']);
        $this->assertSame('typed_history', $timerWait['history_authority']);
        $this->assertNull($timerWait['history_unsupported_reason']);
    }

    public function testRunDetailViewPrefersOpenTimerTaskOverHistoricalClosedTimerTask(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-timer-task-pref',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
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
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(10),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
            'run_count' => 1,
        ])->save();

        /** @var \Workflow\V2\Models\WorkflowTimer $timer */
        $timer = \Workflow\V2\Models\WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => \Workflow\V2\Enums\TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
            'created_at' => now()
                ->subSeconds(40),
            'updated_at' => now()
                ->subSeconds(40),
        ]);

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Completed->value,
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'available_at' => now()
                ->subSeconds(35),
            'created_at' => now()
                ->subSeconds(35),
            'updated_at' => now()
                ->subSeconds(30),
        ]);

        /** @var WorkflowTask $readyTask */
        $readyTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'available_at' => now()
                ->subSeconds(5),
            'created_at' => now()
                ->subSeconds(5),
            'updated_at' => now()
                ->subSeconds(5),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertTrue($timerWait['task_backed']);
        $this->assertSame($readyTask->id, $timerWait['task_id']);
        $this->assertSame('timer', $timerWait['task_type']);
        $this->assertSame('ready', $timerWait['task_status']);
    }

    public function testRunDetailViewMarksRepairNeededTimerWaitAsNotTaskBacked(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer-repair');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();
        $this->assertSame('timer', $workflow->refresh()->summary()?->wait_kind);

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertFalse($timerWait['task_backed']);
        $this->assertNull($timerWait['task_id']);
        $this->assertNull($timerWait['task_type']);
        $this->assertNull($timerWait['task_status']);

        $missingTask = $this->findTask($detail['tasks'], 'timer');
        $this->assertTrue($missingTask['task_missing']);
        $this->assertTrue($missingTask['synthetic']);
        $this->assertSame('missing', $missingTask['transport_state']);
        $this->assertSame('missing', $missingTask['status']);
        $this->assertSame($timerWait['resume_source_id'], $missingTask['timer_id']);
        $this->assertSame($timerWait['sequence'], $missingTask['timer_sequence']);
    }

    public function testRunDetailViewMarksRowOnlyRunningActivityFallbackAsUnsupportedHistory(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-running-activity',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
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
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var \Workflow\V2\Models\ActivityExecution $execution */
        $execution = \Workflow\V2\Models\ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'activity_type' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'status' => \Workflow\V2\Enums\ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()
                ->subSeconds(20),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $activityWait = $this->findWait($detail['waits'], 'activity');

        $this->assertNull($detail['wait_kind']);
        $this->assertNull($detail['open_wait_id']);
        $this->assertNull($detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertSame(
            sprintf(
                'Activity %s is visible only from an older mutable row without typed activity history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                \Tests\Fixtures\V2\TestGreetingActivity::class,
            ),
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('unsupported_history', $detail['repair_blocked_reason']);
        $this->assertSame('open', $activityWait['status']);
        $this->assertSame('running', $activityWait['source_status']);
        $this->assertSame(
            sprintf(
                'Activity %s is visible only from an older mutable row without typed activity history.',
                \Tests\Fixtures\V2\TestGreetingActivity::class,
            ),
            $activityWait['summary'],
        );
        $this->assertTrue($activityWait['diagnostic_only']);
        $this->assertFalse($activityWait['task_backed']);
        $this->assertSame('mutable_open_fallback', $activityWait['history_authority']);
        $this->assertNull($activityWait['resume_source_kind']);
        $this->assertNull($activityWait['resume_source_id']);
        $this->assertNull($activityWait['task_id']);
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'activity'));
    }

    public function testRunDetailViewMarksRowOnlyPendingTimerFallbackAsUnsupportedHistory(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-row-only-timer',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
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
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
            'created_at' => now()
                ->subSeconds(20),
            'updated_at' => now()
                ->subSeconds(20),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertNull($detail['wait_kind']);
        $this->assertNull($detail['open_wait_id']);
        $this->assertNull($detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertSame(
            sprintf(
                'Timer %s is visible only from an older mutable row without typed timer history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                $timer->id,
            ),
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('unsupported_history', $detail['repair_blocked_reason']);
        $this->assertCount(1, $detail['timers']);
        $this->assertSame($timer->id, $detail['timers'][0]['id']);
        $this->assertSame('pending', $detail['timers'][0]['status']);
        $this->assertSame('mutable_open_fallback', $detail['timers'][0]['history_authority']);
        $this->assertNull($detail['timers'][0]['history_unsupported_reason']);
        $this->assertSame('open', $timerWait['status']);
        $this->assertSame('pending', $timerWait['source_status']);
        $this->assertSame(
            'Timer is visible only from an older mutable row without typed timer history.',
            $timerWait['summary'],
        );
        $this->assertTrue($timerWait['diagnostic_only']);
        $this->assertFalse($timerWait['task_backed']);
        $this->assertSame('mutable_open_fallback', $timerWait['history_authority']);
        $this->assertNull($timerWait['resume_source_kind']);
        $this->assertNull($timerWait['resume_source_id']);
        $this->assertNull($timerWait['task_id']);
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'timer'));
    }

    public function testRunDetailViewMarksRowOnlyOpenChildFallbackAsUnsupportedHistory(): void
    {
        $parentInstance = WorkflowInstance::query()->create([
            'id' => 'detail-row-only-child-parent',
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'detail-row-only-child-child',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subSeconds(50),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $parentInstance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()
                ->subSeconds(40),
            'updated_at' => now()
                ->subSeconds(40),
        ]);

        RunSummaryProjector::project(
            $run->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
                'childLinks.childRun.historyEvents',
            ])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertNull($detail['wait_kind']);
        $this->assertNull($detail['open_wait_id']);
        $this->assertNull($detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertSame(
            'Child workflow workflow.child is visible only from an older mutable row or link without typed parent child history. This state is diagnostic-only and does not satisfy the durable resume-path invariant.',
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('unsupported_history', $detail['repair_blocked_reason']);
        $this->assertSame('open', $childWait['status']);
        $this->assertSame('waiting', $childWait['source_status']);
        $this->assertSame(
            'Child workflow workflow.child is visible only from an older mutable row or link without typed parent child history.',
            $childWait['summary'],
        );
        $this->assertTrue($childWait['diagnostic_only']);
        $this->assertFalse($childWait['task_backed']);
        $this->assertSame('mutable_open_fallback', $childWait['history_authority']);
        $this->assertNull($childWait['resume_source_kind']);
        $this->assertNull($childWait['resume_source_id']);
        $this->assertSame($link->id, $childWait['child_call_id']);
        $this->assertSame($childRun->id, $childWait['child_workflow_run_id']);
        $this->assertSame($childInstance->id, $childWait['target_name']);
        $this->assertTrue($detail['continuedWorkflows'][0]['diagnostic_only']);
        $this->assertSame('mutable_open_fallback', $detail['continuedWorkflows'][0]['history_authority']);
        $this->assertSame($link->id, $detail['continuedWorkflows'][0]['child_call_id']);
        $this->assertSame($childRun->id, $detail['continuedWorkflows'][0]['child_workflow_run_id']);
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'workflow'));
    }

    public function testRunDetailViewKeepsRunningActivityFromTypedHistoryWhenExecutionRowIsMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-running-activity-history',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
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
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'activity_type' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'status' => \Workflow\V2\Enums\ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $execution->forceFill([
            'status' => \Workflow\V2\Enums\ActivityStatus::Running->value,
            'started_at' => now()
                ->subSeconds(15),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $executionId = $execution->id;
        $execution->delete();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $activityWait = $this->findWait($detail['waits'], 'activity');

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame('activity_running_without_task', $detail['liveness_state']);
        $this->assertSame(
            sprintf(
                'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                $executionId,
            ),
            $detail['liveness_reason'],
        );
        $this->assertCount(1, $detail['activities']);
        $this->assertSame($executionId, $detail['activities'][0]['id']);
        $this->assertSame('running', $detail['activities'][0]['status']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['type']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $detail['activities'][0]['class']);
        $this->assertSame(['Taylor'], unserialize($detail['activities'][0]['arguments']));
        $this->assertNotNull($detail['activities'][0]['started_at']);
        $this->assertSame('open', $activityWait['status']);
        $this->assertSame('running', $activityWait['source_status']);
        $this->assertFalse($activityWait['task_backed']);
        $this->assertSame('activity_execution', $activityWait['resume_source_kind']);
        $this->assertSame($executionId, $activityWait['resume_source_id']);
        $this->assertSame(['ActivityScheduled', 'ActivityStarted'], array_column($detail['timeline'], 'type'));
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'activity'));
    }

    public function testRunDetailViewRebuildsActivityAttemptsFromTypedHistoryWhenAttemptRowsAndTaskRowsAreMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-activity-attempt-history',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'activity_type' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'status' => \Workflow\V2\Enums\ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 0,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        /** @var WorkflowTask $firstTask */
        $firstTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'activities',
            'available_at' => now()
                ->subMinute(),
            'leased_at' => now()
                ->subSeconds(55),
            'lease_owner' => 'worker-one',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 1,
        ]);

        $firstAttemptId = (string) \Illuminate\Support\Str::ulid();
        $execution->forceFill([
            'status' => \Workflow\V2\Enums\ActivityStatus::Running->value,
            'attempt_count' => 1,
            'current_attempt_id' => $firstAttemptId,
            'started_at' => now()
                ->subSeconds(55),
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $firstAttemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => 1,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $firstTask);

        $execution->forceFill([
            'status' => \Workflow\V2\Enums\ActivityStatus::Pending->value,
            'last_heartbeat_at' => null,
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $firstAttemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'retry_after_attempt_id' => $firstAttemptId,
            'retry_after_attempt' => 1,
            'retry_backoff_seconds' => 5,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $firstTask);

        /** @var WorkflowTask $secondTask */
        $secondTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [
                'activity_execution_id' => $execution->id,
                'retry_after_attempt_id' => $firstAttemptId,
                'retry_after_attempt' => 1,
            ],
            'connection' => 'redis',
            'queue' => 'activities',
            'available_at' => now()
                ->subSeconds(30),
            'leased_at' => now()
                ->subSeconds(25),
            'lease_owner' => 'worker-two',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 2,
        ]);

        $secondAttemptId = (string) \Illuminate\Support\Str::ulid();
        $heartbeatAt = now()
            ->subSeconds(10);
        $leaseExpiresAt = now()
            ->addMinutes(5);

        $execution->forceFill([
            'status' => \Workflow\V2\Enums\ActivityStatus::Running->value,
            'attempt_count' => 2,
            'current_attempt_id' => $secondAttemptId,
            'started_at' => now()
                ->subSeconds(25),
            'last_heartbeat_at' => $heartbeatAt,
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $secondAttemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => 2,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $secondTask);

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityHeartbeatRecorded, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $secondAttemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => 2,
            'heartbeat_at' => $heartbeatAt->toJSON(),
            'lease_expires_at' => $leaseExpiresAt->toJSON(),
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => [
                'id' => $secondAttemptId,
                'attempt_number' => 2,
                'status' => 'running',
                'task_id' => $secondTask->id,
                'lease_owner' => 'worker-two',
                'started_at' => $execution->started_at?->toJSON(),
                'last_heartbeat_at' => $heartbeatAt->toJSON(),
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
            ],
        ], $secondTask);

        $executionId = $execution->id;
        $firstTaskId = $firstTask->id;
        $secondTaskId = $secondTask->id;

        $firstTask->delete();
        $secondTask->delete();
        $execution->delete();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame('activity_running_without_task', $detail['liveness_state']);
        $this->assertCount(1, $detail['activities']);
        $this->assertSame($executionId, $detail['activities'][0]['id']);
        $this->assertSame($secondAttemptId, $detail['activities'][0]['attempt_id']);
        $this->assertSame(2, $detail['activities'][0]['attempt_count']);
        $this->assertSame('running', $detail['activities'][0]['status']);
        $this->assertCount(2, $detail['activities'][0]['attempts']);
        $this->assertSame($firstAttemptId, $detail['activities'][0]['attempts'][0]['id']);
        $this->assertSame($firstTaskId, $detail['activities'][0]['attempts'][0]['task_id']);
        $this->assertSame('worker-one', $detail['activities'][0]['attempts'][0]['lease_owner']);
        $this->assertSame('failed', $detail['activities'][0]['attempts'][0]['status']);
        $this->assertSame('attempt_closed', $detail['activities'][0]['attempts'][0]['stop_reason']);
        $this->assertSame($secondAttemptId, $detail['activities'][0]['attempts'][1]['id']);
        $this->assertSame($secondTaskId, $detail['activities'][0]['attempts'][1]['task_id']);
        $this->assertSame('worker-two', $detail['activities'][0]['attempts'][1]['lease_owner']);
        $this->assertSame('running', $detail['activities'][0]['attempts'][1]['status']);
        $this->assertTrue($detail['activities'][0]['attempts'][1]['can_continue']);
        $this->assertFalse($detail['activities'][0]['attempts'][1]['cancel_requested']);
        $this->assertNull($detail['activities'][0]['attempts'][1]['stop_reason']);
        $this->assertSame(
            $heartbeatAt->toJSON(),
            $detail['activities'][0]['attempts'][1]['last_heartbeat_at']?->toJSON()
        );
        $this->assertSame(
            $leaseExpiresAt->toJSON(),
            $detail['activities'][0]['attempts'][1]['lease_expires_at']?->toJSON()
        );
        $this->assertSame(
            [
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityRetryScheduled',
                'ActivityStarted',
                'ActivityHeartbeatRecorded',
            ],
            array_column($detail['timeline'], 'type')
        );
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'activity'));
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function waitFor(callable $condition): void
    {
        $startedAt = microtime(true);

        while ((microtime(true) - $startedAt) < 5) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Condition was not met within 5 seconds.');
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function runNextReadyTask(): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail('Expected a ready workflow task.');
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $waits
     * @return array<string, mixed>
     */
    private function findWait(array $waits, string $kind, ?string $targetName = null): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) !== $kind) {
                continue;
            }

            if ($targetName !== null && ($wait['target_name'] ?? null) !== $targetName) {
                continue;
            }

            return $wait;
        }

        $this->fail(sprintf('Did not find %s wait in detail payload.', $kind));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTask(array $tasks, string $type): array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        $this->fail(sprintf('Did not find %s task in detail payload.', $type));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function findTaskOrNull(array $tasks, string $type): ?array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        return null;
    }

    private function findOpenTaskOrNull(array $tasks, string $type): ?array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type && ($task['is_open'] ?? false) === true) {
                return $task;
            }
        }

        return null;
    }
}
