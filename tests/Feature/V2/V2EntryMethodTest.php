<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fixtures\V2\TestExecuteCompatibilityWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestMixedEntryActivityWorkflow;
use Tests\Fixtures\V2\TestMixedEntryChildParentWorkflow;
use Tests\Fixtures\V2\TestMixedEntryChildWorkflow;
use Tests\Fixtures\V2\TestMixedEntryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Webhooks;
use Workflow\V2\WorkflowStub;

final class V2EntryMethodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.webhook_auth.method', 'none');
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testHandleIsTheCanonicalV2WorkflowAndActivityEntryMethod(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'handle-entry-method');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Taylor!', $workflow->output()['greeting']);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $summary = WorkflowRunSummary::query()->findOrFail($workflow->runId());

        $this->assertSame('handle', $detail['declared_entry_method']);
        $this->assertSame('canonical', $detail['declared_entry_mode']);
        $this->assertSame(TestGreetingWorkflow::class, $detail['declared_entry_declaring_class']);
        $this->assertSame('canonical', $summary->declared_entry_mode);
        $this->assertSame('durable_history', $summary->declared_contract_source);
        $this->assertFalse($summary->declared_contract_backfill_needed);
        $this->assertFalse($summary->declared_contract_backfill_available);
    }

    public function testExecuteBasedV2WorkflowsRemainLoadableForCompatibility(): void
    {
        $workflow = WorkflowStub::make(TestExecuteCompatibilityWorkflow::class, 'execute-entry-method');
        $workflow->start('Jordan');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Jordan!', $workflow->output()['greeting']);
        $this->assertSame('Hello, Jordan!', $workflow->query('greeting'));

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $summary = WorkflowRunSummary::query()->findOrFail($workflow->runId());

        $this->assertSame('execute', $detail['declared_entry_method']);
        $this->assertSame('compatibility', $detail['declared_entry_mode']);
        $this->assertSame(TestExecuteCompatibilityWorkflow::class, $detail['declared_entry_declaring_class']);
        $this->assertSame('compatibility', $summary->declared_entry_mode);
        $this->assertSame('durable_history', $summary->declared_contract_source);
        $this->assertFalse($summary->declared_contract_backfill_needed);
        $this->assertFalse($summary->declared_contract_backfill_available);
    }

    public function testWebhookStartStillSupportsExecuteBasedCompatibilityWorkflows(): void
    {
        Webhooks::routes([TestExecuteCompatibilityWorkflow::class]);

        $response = $this->postJson('/webhooks/start/test-execute-compatibility-workflow', [
            'workflow_id' => 'execute-compatibility-webhook',
            'name' => 'Casey',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('workflow_type', 'test-execute-compatibility-workflow');

        $workflow = WorkflowStub::load('execute-compatibility-webhook');

        $this->drainReadyTasks();

        $workflow->refresh();

        $this->assertSame('Hello, Casey!', $workflow->output()['greeting']);
        $this->assertSame('Hello, Casey!', $workflow->query('greeting'));
    }

    public function testMixedWorkflowEntryHierarchyIsRejectedBeforeStartCreatesDurableRows(): void
    {
        $workflow = WorkflowStub::make(TestMixedEntryWorkflow::class, 'mixed-entry-workflow');

        try {
            $workflow->start('Taylor');
            $this->fail('Expected a mixed entry-method hierarchy to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'cannot mix handle() and execute() across its inheritance chain',
                $exception->getMessage()
            );
        }

        $instance = WorkflowInstance::query()->findOrFail('mixed-entry-workflow');

        $this->assertNull($instance->current_run_id);
        $this->assertSame(0, $instance->run_count);
        $this->assertDatabaseMissing('workflow_runs', [
            'workflow_instance_id' => 'mixed-entry-workflow',
        ]);
        $this->assertDatabaseMissing('workflow_commands', [
            'workflow_instance_id' => 'mixed-entry-workflow',
        ]);
    }

    public function testWebhookStartRejectsMixedWorkflowEntryHierarchyAsValidationError(): void
    {
        Webhooks::routes([TestMixedEntryWorkflow::class]);

        $this->postJson('/webhooks/start/test-mixed-entry-workflow', [
            'workflow_id' => 'mixed-entry-webhook',
            'name' => 'Taylor',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow']);
    }

    public function testMixedActivityEntryHierarchyFailsBeforeSchedulingDurableActivityRows(): void
    {
        $workflow = WorkflowStub::make(TestMixedEntryActivityWorkflow::class, 'mixed-entry-activity-workflow');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::ActivityScheduled->value,
        ]);
    }

    public function testMixedChildWorkflowEntryHierarchyFailsBeforeSchedulingDurableChildRows(): void
    {
        $workflow = WorkflowStub::make(TestMixedEntryChildParentWorkflow::class, 'mixed-entry-child-parent-workflow');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_instances', [
            'workflow_class' => TestMixedEntryChildWorkflow::class,
            'workflow_type' => 'test-mixed-entry-child-workflow',
        ]);
        $this->assertDatabaseMissing('workflow_runs', [
            'workflow_class' => TestMixedEntryChildWorkflow::class,
            'workflow_type' => 'test-mixed-entry-child-workflow',
        ]);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
        ]);
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
}
