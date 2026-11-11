<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestActivityAttemptBudgetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * A per-call activity attempt budget counts the first attempt, so a new
 * activity with a budget below one is refused before anything is written
 * (#860).
 */
final class V2ActivityAttemptBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function invalidBudgets(): iterable
    {
        yield 'activity zero' => [0, false];
        yield 'activity negative' => [-1, false];
        yield 'local activity zero' => [0, true];
        yield 'local activity negative' => [-1, true];
    }

    #[DataProvider('invalidBudgets')]
    public function testANewActivityWithABudgetBelowOneFailsTheRunBeforeScheduling(int $budget, bool $local): void
    {
        $workflow = WorkflowStub::make(TestActivityAttemptBudgetWorkflow::class);
        $workflow->start($budget, $local);
        $this->drainReadyTasks();

        $workflow->refresh();
        $this->assertTrue($workflow->failed(), $workflow->status());
        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->count());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function activityKinds(): iterable
    {
        yield 'activity' => [false];
        yield 'local activity' => [true];
    }

    #[DataProvider('activityKinds')]
    public function testABudgetOfOneRunsTheActivityOnce(bool $local): void
    {
        $workflow = WorkflowStub::make(TestActivityAttemptBudgetWorkflow::class);
        $workflow->start(1, $local);
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed(), $workflow->status());
        $this->assertSame('Hello, budget!', $workflow->output());
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertSame(1, $execution->retry_policy['max_attempts']);
    }

    public function testAHistoryThatRecordedAZeroBudgetBeforeTheCheckStillReplays(): void
    {
        $workflow = WorkflowStub::make(TestActivityAttemptBudgetWorkflow::class);
        $workflow->start(1, false);
        $this->runReadyTask(TaskType::Workflow);

        // Rewrite this run into what an older release recorded for code that
        // passes maxAttempts 0: the run's input, the activity's options and
        // retry snapshot, and the ActivityScheduled history.
        $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($workflow->runId());
        $run->forceFill([
            'arguments' => \Workflow\Serializers\Serializer::serializeWithCodec(
                (string) $run->payload_codec,
                [0, false]
            ),
        ])->save();
        $execution = ActivityExecution::query()->where('workflow_run_id', $run->id)->sole();
        $execution->forceFill([
            'retry_policy' => array_merge($execution->retry_policy, [
                'max_attempts' => 0,
            ]),
            'activity_options' => array_merge($execution->activity_options ?? [], [
                'max_attempts' => 0,
            ]),
        ])->save();
        foreach (\Workflow\V2\Models\WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', \Workflow\V2\Enums\HistoryEventType::ActivityScheduled->value)
            ->get() as $event) {
            $payload = $event->payload;
            foreach (['activity_options', 'retry_policy'] as $key) {
                if (is_array($payload[$key] ?? null)) {
                    $payload[$key]['max_attempts'] = 0;
                }
                if (is_array($payload['activity'][$key] ?? null)) {
                    $payload['activity'][$key]['max_attempts'] = 0;
                }
            }
            $event->forceFill([
                'payload' => $payload,
            ])->save();
        }

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed(), $workflow->status());
        $this->assertSame('Hello, budget!', $workflow->output());
        $this->assertSame(
            1,
            \Workflow\V2\Support\ActivityRetryPolicy::maxAttemptsFromSnapshot($execution->fresh()),
        );
    }

    private function runReadyTask(TaskType $type): void
    {
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->where('task_type', $type->value)
            ->orderBy('created_at')
            ->firstOrFail();
        $job = $type === TaskType::Workflow ? new RunWorkflowTask($task->id) : new RunActivityTask($task->id);
        $this->app->call([$job, 'handle']);
    }

    private function drainReadyTasks(): void
    {
        for ($i = 0; $i < 50; ++$i) {
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

        $this->fail('Tasks did not drain.');
    }
}
