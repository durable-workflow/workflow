<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestActivityOptionsWorkflow;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestHistoryBudgetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\WorkflowStub;

final class V2ActivityOptionsTest extends TestCase
{
    public function testActivityOptionsOverridesRoutingOnScheduledExecution(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestActivityOptionsWorkflow::class, 'ao-test-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $executions = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $executions);

        $defaultExecution = $executions->first();
        $this->assertNull($defaultExecution->activity_options);

        $customExecution = $executions->last();
        $this->assertSame('custom-conn', $customExecution->connection);
        $this->assertSame('high-priority', $customExecution->queue);
        $this->assertIsArray($customExecution->activity_options);
        $this->assertSame('custom-conn', $customExecution->activity_options['connection']);
        $this->assertSame('high-priority', $customExecution->activity_options['queue']);
    }

    public function testActivityOptionsOverridesRetryPolicy(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestActivityOptionsWorkflow::class, 'ao-test-2');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $executions = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $executions);

        $defaultExecution = $executions->first();
        $defaultRetryPolicy = $defaultExecution->retry_policy;
        $this->assertSame(1, $defaultRetryPolicy['max_attempts']);

        $customExecution = $executions->last();
        $customRetryPolicy = $customExecution->retry_policy;
        $this->assertSame(5, $customRetryPolicy['max_attempts']);
        $this->assertSame([1, 5, 15], $customRetryPolicy['backoff_seconds']);
    }

    public function testActivityOptionsSnapshotPersistedOnExecution(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestActivityOptionsWorkflow::class, 'ao-test-3');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $customExecution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence', 'desc')
            ->first();

        $options = $customExecution->activity_options;
        $this->assertIsArray($options);
        $this->assertSame('custom-conn', $options['connection']);
        $this->assertSame('high-priority', $options['queue']);
        $this->assertSame(5, $options['max_attempts']);
        $this->assertSame([1, 5, 15], $options['backoff']);
    }

    public function testActivityOptionsTimeoutFields(): void
    {
        $options = new ActivityOptions(
            startToCloseTimeout: 120,
            scheduleToStartTimeout: 30,
        );

        $this->assertSame(120, $options->startToCloseTimeout);
        $this->assertSame(30, $options->scheduleToStartTimeout);
        $this->assertTrue($options->hasTimeoutOverrides());
        $this->assertFalse($options->hasRoutingOverrides());
        $this->assertFalse($options->hasRetryOverrides());

        $snapshot = $options->toSnapshot();
        $this->assertSame(120, $snapshot['start_to_close_timeout']);
        $this->assertSame(30, $snapshot['schedule_to_start_timeout']);
    }

    public function testActivityCallWithOptionsBuilderPattern(): void
    {
        $options = new ActivityOptions(queue: 'critical');

        $call = new \Workflow\V2\Support\ActivityCall('App\\MyActivity', ['arg1']);
        $withOptions = $call->withOptions($options);

        $this->assertNull($call->options);
        $this->assertSame($options, $withOptions->options);
        $this->assertSame('App\\MyActivity', $withOptions->activity);
        $this->assertSame(['arg1'], $withOptions->arguments);
    }

    public function testActivityOptionsHasOverrideHelpers(): void
    {
        $empty = new ActivityOptions();
        $this->assertFalse($empty->hasRoutingOverrides());
        $this->assertFalse($empty->hasRetryOverrides());
        $this->assertFalse($empty->hasTimeoutOverrides());

        $routing = new ActivityOptions(connection: 'redis');
        $this->assertTrue($routing->hasRoutingOverrides());
        $this->assertFalse($routing->hasRetryOverrides());

        $retry = new ActivityOptions(maxAttempts: 3);
        $this->assertFalse($retry->hasRoutingOverrides());
        $this->assertTrue($retry->hasRetryOverrides());

        $timeout = new ActivityOptions(startToCloseTimeout: 60);
        $this->assertTrue($timeout->hasTimeoutOverrides());
    }

    public function testWorkflowCompletesWithBothDefaultAndCustomActivities(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestActivityOptionsWorkflow::class, 'ao-test-5');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'default' => 'Hello, Taylor!',
            'custom' => 'Hello, Taylor!',
            'workflow_id' => 'ao-test-5',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        WorkflowStub::assertDispatchedTimes(TestGreetingActivity::class, 2);
    }

    public function testHistoryBudgetExposedToWorkflowCode(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestHistoryBudgetWorkflow::class, 'hb-test-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertIsInt($output['history_length']);
        $this->assertGreaterThan(0, $output['history_length']);
        $this->assertIsInt($output['history_size']);
        $this->assertGreaterThan(0, $output['history_size']);
        $this->assertFalse($output['should_continue_as_new']);
    }

    public function testHistoryEventSequenceIncludesAllActivities(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestActivityOptionsWorkflow::class, 'ao-test-6');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], $eventTypes);
    }
}
