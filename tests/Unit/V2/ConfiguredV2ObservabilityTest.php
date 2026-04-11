<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
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
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunDetailView;

final class ConfiguredV2ObservabilityTest extends TestCase
{
    /**
     * @dataProvider relationMatrixProvider
     *
     * @param class-string<object> $modelClass
     * @param class-string<object> $configuredClass
     */
    public function testModelRelationsResolveConfiguredV2Classes(
        string $modelClass,
        string $relation,
        string $configKey,
        string $configuredClass,
    ): void {
        $this->configureAllModelOverrides();

        /** @var object $model */
        $model = new $modelClass();
        $related = $model->{$relation}()
            ->getRelated();

        $this->assertSame($configuredClass, $related::class, sprintf(
            '%s::%s() should resolve %s from workflows.v2.%s',
            $modelClass,
            $relation,
            $configuredClass,
            $configKey,
        ));
    }

    public function testConfiguredV2ModelsFallsBackToDefaultClassForInvalidOverrides(): void
    {
        config()->set('workflows.v2.run_model', \stdClass::class);

        $this->assertSame(WorkflowRun::class, ConfiguredV2Models::resolve('run_model', WorkflowRun::class));
        $this->assertSame(
            WorkflowRun::class,
            ConfiguredV2Models::query('run_model', WorkflowRun::class)->getModel()::class,
        );
    }

    public function testCurrentRunResolverUsesConfiguredRunModel(): void
    {
        $this->createConfiguredRunsTable();
        config()
            ->set('workflows.v2.run_model', ConfiguredWorkflowRun::class);

        $instance = WorkflowInstance::query()->create([
            'id' => 'configured-current-run-instance',
            'workflow_class' => 'App\\Workflows\\ConfiguredResolverWorkflow',
            'workflow_type' => 'configured.resolver.workflow',
            'run_count' => 2,
        ]);

        ConfiguredWorkflowRun::query()->create([
            'id' => '01JCONFIGRUN00000000000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ConfiguredResolverWorkflow',
            'workflow_type' => 'configured.resolver.workflow',
            'status' => RunStatus::Completed->value,
            'started_at' => now()
                ->subMinutes(3),
            'closed_at' => now()
                ->subMinutes(2),
            'created_at' => now()
                ->subMinutes(3),
            'updated_at' => now()
                ->subMinutes(2),
        ]);

        ConfiguredWorkflowRun::query()->create([
            'id' => '01JCONFIGRUN00000000000002',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'App\\Workflows\\ConfiguredResolverWorkflow',
            'workflow_type' => 'configured.resolver.workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subSeconds(30),
        ]);

        $resolved = CurrentRunResolver::forInstance($instance->fresh());

        $this->assertInstanceOf(ConfiguredWorkflowRun::class, $resolved);
        $this->assertSame('01JCONFIGRUN00000000000002', $resolved?->id);
    }

    public function testRunDetailAndHistoryExportUseConfiguredSummaryAndHistoryModels(): void
    {
        $this->createConfiguredSummariesTable();
        $this->createConfiguredHistoryEventsTable();

        config()
            ->set('workflows.v2.run_summary_model', ConfiguredWorkflowRunSummary::class);
        config()
            ->set('workflows.v2.history_event_model', ConfiguredWorkflowHistoryEvent::class);

        $instance = WorkflowInstance::query()->create([
            'id' => 'configured-observability-instance',
            'workflow_class' => 'Missing\\ConfiguredObservabilityWorkflow',
            'workflow_type' => 'configured.observability.workflow',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => '01JCONFIGDETAILRUN0000000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\ConfiguredObservabilityWorkflow',
            'workflow_type' => 'configured.observability.workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        ConfiguredWorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'Missing\\ConfiguredObservabilityWorkflow',
            'workflow_type' => 'configured.observability.workflow',
            'business_key' => 'configured-business-key',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => $run->started_at,
            'history_event_count' => 1,
            'history_size_bytes' => 256,
            'continue_as_new_recommended' => false,
            'sort_timestamp' => $run->started_at,
            'sort_key' => 'configured-sort-key',
            'created_at' => $run->started_at,
            'updated_at' => $run->last_progress_at,
        ]);

        ConfiguredWorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => 'Missing\\ConfiguredObservabilityWorkflow',
                'workflow_type' => 'configured.observability.workflow',
                'workflow_definition_fingerprint' => 'configured-fingerprint',
                'declared_queries' => [],
                'declared_query_contracts' => [],
                'declared_signals' => ['configured-signal'],
                'declared_signal_contracts' => [],
                'declared_updates' => [],
                'declared_update_contracts' => [],
                'declared_entry_method' => 'handle',
                'declared_entry_mode' => 'canonical',
                'declared_entry_declaring_class' => 'Missing\\ConfiguredObservabilityWorkflow',
            ],
            'recorded_at' => $run->started_at,
        ]);

        $detail = RunDetailView::forRun($run->fresh());
        $export = HistoryExport::forRun($run->fresh());

        $this->assertSame('running', $detail['status_bucket']);
        $this->assertSame('configured-business-key', $detail['business_key']);
        $this->assertSame(['configured-signal'], $detail['declared_signals']);
        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertSame('configured-fingerprint', $detail['workflow_definition_fingerprint']);

        $this->assertSame('running', $export['summary']['status_bucket']);
        $this->assertSame('configured-business-key', $export['summary']['business_key']);
        $this->assertSame(
            'configured-fingerprint',
            $export['history_events'][0]['payload']['workflow_definition_fingerprint']
        );
        $this->assertSame(['configured-signal'], $export['history_events'][0]['payload']['declared_signals']);
    }

    /**
     * @return array<string, array{class-string<object>, string, string, class-string<object>}>
     */
    public static function relationMatrixProvider(): array
    {
        return [
            'activity attempt execution' => [
                ActivityAttempt::class,
                'execution',
                'activity_execution_model',
                ConfiguredActivityExecution::class,
            ],
            'activity attempt run' => [ActivityAttempt::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'activity execution run' => [ActivityExecution::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'activity execution attempts' => [
                ActivityExecution::class,
                'attempts',
                'activity_attempt_model',
                ConfiguredActivityAttempt::class,
            ],
            'command instance' => [
                WorkflowCommand::class,
                'instance',
                'instance_model',
                ConfiguredWorkflowInstance::class,
            ],
            'command run' => [WorkflowCommand::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'command history events' => [
                WorkflowCommand::class,
                'historyEvents',
                'history_event_model',
                ConfiguredWorkflowHistoryEvent::class,
            ],
            'command update record' => [
                WorkflowCommand::class,
                'updateRecord',
                'update_model',
                ConfiguredWorkflowUpdate::class,
            ],
            'command signal record' => [
                WorkflowCommand::class,
                'signalRecord',
                'signal_model',
                ConfiguredWorkflowSignal::class,
            ],
            'failure run' => [WorkflowFailure::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'history event run' => [WorkflowHistoryEvent::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'history event command' => [
                WorkflowHistoryEvent::class,
                'command',
                'command_model',
                ConfiguredWorkflowCommand::class,
            ],
            'instance current run' => [
                WorkflowInstance::class,
                'currentRun',
                'run_model',
                ConfiguredWorkflowRun::class,
            ],
            'instance runs' => [WorkflowInstance::class, 'runs', 'run_model', ConfiguredWorkflowRun::class],
            'instance commands' => [
                WorkflowInstance::class,
                'commands',
                'command_model',
                ConfiguredWorkflowCommand::class,
            ],
            'instance updates' => [WorkflowInstance::class, 'updates', 'update_model', ConfiguredWorkflowUpdate::class],
            'link parent run' => [WorkflowLink::class, 'parentRun', 'run_model', ConfiguredWorkflowRun::class],
            'link child run' => [WorkflowLink::class, 'childRun', 'run_model', ConfiguredWorkflowRun::class],
            'run instance' => [WorkflowRun::class, 'instance', 'instance_model', ConfiguredWorkflowInstance::class],
            'run history events' => [
                WorkflowRun::class,
                'historyEvents',
                'history_event_model',
                ConfiguredWorkflowHistoryEvent::class,
            ],
            'run tasks' => [WorkflowRun::class, 'tasks', 'task_model', ConfiguredWorkflowTask::class],
            'run commands' => [WorkflowRun::class, 'commands', 'command_model', ConfiguredWorkflowCommand::class],
            'run updates' => [WorkflowRun::class, 'updates', 'update_model', ConfiguredWorkflowUpdate::class],
            'run signals' => [WorkflowRun::class, 'signals', 'signal_model', ConfiguredWorkflowSignal::class],
            'run activity executions' => [
                WorkflowRun::class,
                'activityExecutions',
                'activity_execution_model',
                ConfiguredActivityExecution::class,
            ],
            'run activity attempts' => [
                WorkflowRun::class,
                'activityAttempts',
                'activity_attempt_model',
                ConfiguredActivityAttempt::class,
            ],
            'run timers' => [WorkflowRun::class, 'timers', 'timer_model', ConfiguredWorkflowTimer::class],
            'run failures' => [WorkflowRun::class, 'failures', 'failure_model', ConfiguredWorkflowFailure::class],
            'run summary' => [WorkflowRun::class, 'summary', 'run_summary_model', ConfiguredWorkflowRunSummary::class],
            'run waits' => [WorkflowRun::class, 'waits', 'run_wait_model', ConfiguredWorkflowRunWait::class],
            'run timeline entries' => [
                WorkflowRun::class,
                'timelineEntries',
                'run_timeline_entry_model',
                ConfiguredWorkflowTimelineEntry::class,
            ],
            'run timer entries' => [
                WorkflowRun::class,
                'timerEntries',
                'run_timer_entry_model',
                ConfiguredWorkflowRunTimerEntry::class,
            ],
            'run lineage entries' => [
                WorkflowRun::class,
                'lineageEntries',
                'run_lineage_entry_model',
                ConfiguredWorkflowRunLineageEntry::class,
            ],
            'run parent links' => [WorkflowRun::class, 'parentLinks', 'link_model', ConfiguredWorkflowLink::class],
            'run child links' => [WorkflowRun::class, 'childLinks', 'link_model', ConfiguredWorkflowLink::class],
            'lineage entry run' => [WorkflowRunLineageEntry::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'summary run' => [WorkflowRunSummary::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'timer entry run' => [WorkflowRunTimerEntry::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'wait run' => [WorkflowRunWait::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'signal instance' => [
                WorkflowSignal::class,
                'instance',
                'instance_model',
                ConfiguredWorkflowInstance::class,
            ],
            'signal run' => [WorkflowSignal::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'signal command' => [WorkflowSignal::class, 'command', 'command_model', ConfiguredWorkflowCommand::class],
            'task run' => [WorkflowTask::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'timeline entry run' => [WorkflowTimelineEntry::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'timer run' => [WorkflowTimer::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'update instance' => [
                WorkflowUpdate::class,
                'instance',
                'instance_model',
                ConfiguredWorkflowInstance::class,
            ],
            'update run' => [WorkflowUpdate::class, 'run', 'run_model', ConfiguredWorkflowRun::class],
            'update command' => [WorkflowUpdate::class, 'command', 'command_model', ConfiguredWorkflowCommand::class],
            'update failure' => [WorkflowUpdate::class, 'failure', 'failure_model', ConfiguredWorkflowFailure::class],
        ];
    }

    private function configureAllModelOverrides(): void
    {
        config()->set('workflows.v2.instance_model', ConfiguredWorkflowInstance::class);
        config()
            ->set('workflows.v2.run_model', ConfiguredWorkflowRun::class);
        config()
            ->set('workflows.v2.history_event_model', ConfiguredWorkflowHistoryEvent::class);
        config()
            ->set('workflows.v2.task_model', ConfiguredWorkflowTask::class);
        config()
            ->set('workflows.v2.command_model', ConfiguredWorkflowCommand::class);
        config()
            ->set('workflows.v2.link_model', ConfiguredWorkflowLink::class);
        config()
            ->set('workflows.v2.activity_execution_model', ConfiguredActivityExecution::class);
        config()
            ->set('workflows.v2.activity_attempt_model', ConfiguredActivityAttempt::class);
        config()
            ->set('workflows.v2.timer_model', ConfiguredWorkflowTimer::class);
        config()
            ->set('workflows.v2.failure_model', ConfiguredWorkflowFailure::class);
        config()
            ->set('workflows.v2.run_summary_model', ConfiguredWorkflowRunSummary::class);
        config()
            ->set('workflows.v2.run_wait_model', ConfiguredWorkflowRunWait::class);
        config()
            ->set('workflows.v2.run_timeline_entry_model', ConfiguredWorkflowTimelineEntry::class);
        config()
            ->set('workflows.v2.run_timer_entry_model', ConfiguredWorkflowRunTimerEntry::class);
        config()
            ->set('workflows.v2.run_lineage_entry_model', ConfiguredWorkflowRunLineageEntry::class);
        config()
            ->set('workflows.v2.signal_model', ConfiguredWorkflowSignal::class);
        config()
            ->set('workflows.v2.update_model', ConfiguredWorkflowUpdate::class);
    }

    private function createConfiguredRunsTable(): void
    {
        Schema::create('configured_workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('status');
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('last_progress_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    private function createConfiguredSummariesTable(): void
    {
        Schema::create('configured_workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')
                ->default(false);
            $table->string('engine_source')
                ->nullable();
            $table->string('class');
            $table->string('workflow_type');
            $table->string('business_key')
                ->nullable();
            $table->string('status');
            $table->string('status_bucket')
                ->nullable();
            $table->string('closed_reason')
                ->nullable();
            $table->string('repair_blocked_reason')
                ->nullable();
            $table->string('declared_entry_mode')
                ->nullable();
            $table->string('declared_contract_source')
                ->nullable();
            $table->boolean('declared_contract_backfill_needed')
                ->default(false);
            $table->boolean('declared_contract_backfill_available')
                ->default(false);
            $table->unsignedInteger('history_event_count')
                ->default(0);
            $table->unsignedBigInteger('history_size_bytes')
                ->default(0);
            $table->boolean('continue_as_new_recommended')
                ->default(false);
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('sort_timestamp', 6)
                ->nullable();
            $table->string('sort_key')
                ->nullable();
            $table->timestamps(6);
        });
    }

    private function createConfiguredHistoryEventsTable(): void
    {
        Schema::create('configured_workflow_history_events', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')
                ->nullable();
            $table->timestamp('recorded_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }
}

final class ConfiguredWorkflowInstance extends WorkflowInstance
{
}

final class ConfiguredWorkflowRun extends WorkflowRun
{
    protected $table = 'configured_workflow_runs';
}

final class ConfiguredWorkflowHistoryEvent extends WorkflowHistoryEvent
{
    protected $table = 'configured_workflow_history_events';
}

final class ConfiguredWorkflowTask extends WorkflowTask
{
}

final class ConfiguredWorkflowCommand extends WorkflowCommand
{
}

final class ConfiguredWorkflowLink extends WorkflowLink
{
}

final class ConfiguredActivityExecution extends ActivityExecution
{
}

final class ConfiguredActivityAttempt extends ActivityAttempt
{
}

final class ConfiguredWorkflowTimer extends WorkflowTimer
{
}

final class ConfiguredWorkflowFailure extends WorkflowFailure
{
}

final class ConfiguredWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'configured_workflow_run_summaries';
}

final class ConfiguredWorkflowRunWait extends WorkflowRunWait
{
}

final class ConfiguredWorkflowTimelineEntry extends WorkflowTimelineEntry
{
}

final class ConfiguredWorkflowRunTimerEntry extends WorkflowRunTimerEntry
{
}

final class ConfiguredWorkflowRunLineageEntry extends WorkflowRunLineageEntry
{
}

final class ConfiguredWorkflowSignal extends WorkflowSignal
{
}

final class ConfiguredWorkflowUpdate extends WorkflowUpdate
{
}
