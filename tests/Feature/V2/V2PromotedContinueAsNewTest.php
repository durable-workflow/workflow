<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestPromotedContinueWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ContinueAsNewTarget;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\EmbeddedWorkflowHeartbeat;
use Workflow\V2\Support\RunObservation;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2PromotedContinueAsNewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()
            ->set([
                'workflows.v2.compatibility.continue_as_new_target_build' => true,
                'workflows.v2.compatibility.promoted' => 'build-b',
                'workflows.v2.compatibility.supported' => null,
                'workflows.v2.connection' => 'redis',
                'workflows.v2.queue' => 'target-queue',
                'workflows.v2.types.workflows' => [
                    'test-promoted-continue' => TestPromotedContinueWorkflow::class,
                ],
                'workflows.v2.matching_role.queue_wake_enabled' => false,
            ]);
    }

    public function testRefusalRetriesThenOnlyThePromotedWorkerCanClaimTheSuccessor(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        $run = $this->start('promoted');
        $inherited = $this->start('inherited');
        $task = $this->workflowTask($run);
        $history = $run->historyEvents()
            ->pluck('id')
            ->all();

        // Marker-only fleet advertising does not establish a type binding.
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'target-queue', 'legacy-worker');
        $this->runTask($task);
        $run->refresh();
        $task->refresh();
        $this->assertFalse($run->status->isTerminal());
        $this->assertSame($history, $run->historyEvents()->pluck('id')->all());
        $this->assertSame(1, $run->instance->run_count);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->lease_owner);
        $observation = RunObservation::forRun($run);
        $refusal = $observation['continue_as_new_refusal'];
        $this->assertSame('promoted_build_unavailable', $refusal['reason']);
        $this->assertSame($task->id, $refusal['task_id']);
        $this->assertSame(1, $refusal['workflow_task_attempt']);
        $this->assertNotNull($refusal['available_at']);
        $this->assertNull($observation['continue_as_new_build_transitions']['to_successor']);

        $task->forceFill([
            'status' => TaskStatus::Failed,
        ])->save();
        $this->assertNull(RunObservation::forRun($run)['continue_as_new_refusal']['available_at']);
        $task->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        config()
            ->set('workflows.v2.compatibility.current', 'build-b');
        event(new Looping('redis', 'unrelated,target-queue', new WorkerOptions()));
        $this->assertNotNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        $this->travelTo($task->available_at->copy()->addSecond());
        $this->runTask($task);
        $this->runTask($this->workflowTask($inherited));
        $successor = $run->instance->fresh()
->currentRun;
        $this->assertSame('build-b', $successor->compatibility);
        $this->assertSame('build-a', $inherited->instance->fresh()->currentRun->compatibility);
        $this->assertSame(2, $task->fresh()->attempt_count);
        $this->assertNull(RunObservation::forRun($run->fresh())['continue_as_new_refusal']);
        $transition = [
            'from_compatibility' => 'build-a',
            'to_compatibility' => 'build-b',
            'resolution' => 'promoted',
        ];
        $this->assertEquals(
            [
                'run_id' => $successor->id,
                ...$transition,
            ],
            RunObservation::forRun($run->fresh())['continue_as_new_build_transitions']['to_successor'],
        );
        $this->assertEquals(
            [
                'run_id' => $run->id,
                ...$transition,
            ],
            RunObservation::forRun($successor)['continue_as_new_build_transitions']['from_predecessor'],
        );
        $started = $successor->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowStarted->value)->sole();
        $this->assertSame(
            WorkflowDefinition::fingerprint(TestPromotedContinueWorkflow::class),
            $started->payload['workflow_definition_fingerprint']
        );

        $successorTask = $this->workflowTask($successor);
        config()
            ->set('workflows.v2.compatibility.supported', ['*']);
        $this->runTask($successorTask);
        $this->assertSame(0, $successorTask->fresh()->attempt_count);
        config()
            ->set('workflows.v2.compatibility.supported', null);
        config()
            ->set('workflows.v2.compatibility.current', 'build-b');
        $this->runTask($successorTask);
        $this->assertSame(RunStatus::Completed, $successor->fresh()->status);

        config()
            ->set([
                'workflows.v2.compatibility.promoted' => 'build-c',
                'workflows.v2.compatibility.continue_as_new_target_build' => false,
            ]);
        $replay = (new WorkflowReplayer())->replay($run->fresh());
        $this->assertInstanceOf(ContinueAsNewCall::class, $replay->current);
        $this->assertSame(ContinueAsNewTarget::Promoted, $replay->current->target);
        $this->assertNull((new WorkflowReplayer())->replay($successor->fresh())->current);
    }

    public function testEligibilityRequiresLiveEvidenceForEveryScopeComponent(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        $run = $this->start('promoted');
        config()
            ->set('workflows.v2.compatibility.current', 'build-b');

        event(new Looping('redis', 'another-queue', new WorkerOptions()));
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        event(new Looping('another-connection', 'target-queue', new WorkerOptions()));
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        config()
            ->set('workflows.v2.namespace', 'unrelated');
        event(new Looping('redis', 'target-queue', new WorkerOptions()));
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));

        config()
            ->set([
                'workflows.v2.namespace' => null,
                'workflows.v2.types.workflows' => [
                    'test-greeting-workflow' => TestGreetingWorkflow::class,
                ],
            ]);
        event(new Looping('redis', 'target-queue', new WorkerOptions()));
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        config()
            ->set('workflows.v2.types.workflows', [
                'test-promoted-continue' => TestPromotedContinueWorkflow::class,
            ]);
        event(new Looping('redis', 'target-queue', new WorkerOptions()));
        $this->assertNotNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        $this->travel(31)
            ->seconds();
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        event(new Looping('redis', 'target-queue', new WorkerOptions()));
        $this->assertNotNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
        event(new WorkerStopping(0, new WorkerOptions()));
        $this->assertNull(EmbeddedWorkflowHeartbeat::promotedDefinition($run));
    }

    private function start(string $stage): WorkflowRun
    {
        $stub = WorkflowStub::make(TestPromotedContinueWorkflow::class, 'continued-' . $stage);
        $stub->start($stage);

        return WorkflowRun::query()->findOrFail($stub->runId());
    }

    private function workflowTask(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)->sole();
    }

    private function runTask(WorkflowTask $task): void
    {
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
