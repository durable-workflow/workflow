<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestHistoryBudgetLoopWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\WorkflowStub;

/**
 * #859: a history budget read inside a loop must give the same answer on replay as it did originally.
 * Every task here runs on a fresh job with no warm or sticky state, so each one replays from the start.
 */
final class V2HistoryBudgetReplayTest extends TestCase
{
    private const EVENT_THRESHOLD = 14;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.history_budget.continue_as_new_event_threshold', self::EVENT_THRESHOLD);
        config()
            ->set('workflows.v2.history_budget.event_warning_threshold', self::EVENT_THRESHOLD - 2);
        Queue::fake();
    }

    public function testStepStableBudgetContinuesAsNewOnceAtTheFirstRecommendedIteration(): void
    {
        $workflow = WorkflowStub::make(TestHistoryBudgetLoopWorkflow::class, 'budget-loop-stable');
        $workflow->start(true, 20);

        $this->drainReadyTasks();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'budget-loop-stable')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs, 'The loop continues as new exactly once.');
        $first = $runs[0];
        $this->assertSame(RunStatus::Completed, $runs[1]->status);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $first->id)
            ->orderBy('sequence')
            ->get();
        $continued = $events->firstWhere('event_type', HistoryEventType::WorkflowContinuedAsNew);
        $this->assertNotNull($continued);

        // The rollover check saw every event before the continue-as-new and crossed the threshold.
        $beforeRollover = $events->filter(static fn ($event): bool => $event->sequence < $continued->sequence)
            ->count();
        $this->assertGreaterThanOrEqual(self::EVENT_THRESHOLD, $beforeRollover);

        // The check one iteration earlier saw everything before the last ActivityScheduled, which was under it.
        $lastScheduled = $events
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->sortByDesc('sequence')
            ->first();
        $this->assertNotNull($lastScheduled);
        $beforePreviousCheck = $events->filter(static fn ($event): bool => $event->sequence < $lastScheduled->sequence)
            ->count();
        $this->assertLessThan(self::EVENT_THRESHOLD, $beforePreviousCheck);

        $this->assertSame(
            $events->where('event_type', HistoryEventType::ActivityScheduled)->count() - 1,
            WorkflowStub::load('budget-loop-stable')->output()['rolled_over_at'] ?? null,
        );
    }

    public function testStepBudgetIsTheSameAtEveryStepOnReplayAsAtTheFrontier(): void
    {
        $workflow = WorkflowStub::make(TestHistoryBudgetLoopWorkflow::class, 'budget-loop-prefix');
        $workflow->start(true, 20);
        $this->drainReadyTasks();

        /** @var WorkflowRun $first */
        $first = WorkflowRun::query()
            ->where('workflow_instance_id', 'budget-loop-prefix')
            ->orderBy('run_number')
            ->firstOrFail();
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $first->id)->orderBy('sequence')->get();

        // For each step that has a recorded command, the step budget counts exactly the events before it.
        foreach ($events as $event) {
            $step = is_array($event->payload) ? ($event->payload['sequence'] ?? null) : null;
            if (! is_int($step) || $event->event_type !== HistoryEventType::ActivityScheduled) {
                continue;
            }
            $budget = HistoryBudget::forRunAtStep($first->fresh(), $step);
            $this->assertSame(
                $events->filter(static fn ($e): bool => $e->sequence < $event->sequence)
                    ->count(),
                $budget['history_event_count'],
                "step {$step}",
            );
            $this->assertSame(HistoryBudget::STEP_PREFIX_OBSERVATION, $budget['observation']);
        }
    }

    /**
     * Reproduces #859: the whole-history method answers differently on replay, so the run doesn't
     * roll over cleanly once the threshold is crossed. This pins the legacy behavior as unchanged.
     */
    public function testWholeHistoryBudgetInALoopDoesNotRollOverCleanly(): void
    {
        $workflow = WorkflowStub::make(TestHistoryBudgetLoopWorkflow::class, 'budget-loop-legacy');
        $workflow->start(false, 20);

        $this->drainReadyTasks(allowStuck: true);

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'budget-loop-legacy')
            ->orderBy('run_number')
            ->get();
        $continuedOnce = $runs->count() === 2
            && WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runs[0]->id)
                ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
                ->exists();
        $clean = $continuedOnce && $runs[1]->status === RunStatus::Completed;

        $this->assertFalse($clean, 'The legacy whole-history read rolled over cleanly; #859 no longer reproduces.');
    }

    private function drainReadyTasks(bool $allowStuck = false): void
    {
        $deadline = microtime(true) + 20;

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

            try {
                $this->app->call([$job, 'handle']);
            } catch (\Throwable $error) {
                if (! $allowStuck) {
                    throw $error;
                }

                return;
            }
        }

        if (! $allowStuck) {
            $this->fail('Timed out draining ready workflow tasks.');
        }
    }
}
