<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Activity;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\RunTimerProjector;
use Workflow\V2\Support\RunTimerView;
use Workflow\V2\Support\RunWaitProjector;
use Workflow\V2\Support\RunWaitView;

use function Workflow\V2\timer;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ProjectionReplayTest extends TestCase
{
    public function testRepeatedActivityAndTimerRoundsKeepColdProjectionsConsistent(): void
    {
        $rounds = 3;
        $calls = 0;
        WorkflowStub::mock(ProjectionReplayActivity::class, static function ($context, int $value) use (&$calls): int {
            $calls++;

            return $value;
        });
        $this->freezeTime();
        $workflow = WorkflowStub::make(ProjectionReplayWorkflow::class, 'projection-replay');
        $workflow->start($rounds);

        for ($round = 0; $round <= $rounds; $round++) {
            WorkflowStub::runReadyTasks();
            $run = WorkflowRun::query()->findOrFail($workflow->runId());
            $activities = RunActivityView::activitiesForRun($run, decodePayloads: false);
            $timers = RunTimerView::timersForRun($run);

            $this->assertEquals(
                RunWaitView::forRun($run->fresh()),
                RunWaitView::forRun($run, $activities, $timers),
            );
            RunSummaryProjector::project($run->fresh());
            $this->assertFalse(RunTimelineProjector::driftStatusForRun($run->fresh())['stale']);
            $this->assertFalse(RunWaitProjector::driftStatusForRun($run->fresh())['stale']);
            $this->assertFalse(RunTimerProjector::driftStatusForRun($run->fresh())['stale']);
            $this->travel(2)
                ->seconds();
        }

        $workflow = WorkflowStub::load('projection-replay');
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertTrue($workflow->completed());
        $this->assertSame($rounds * 3, $calls);
        $this->assertSame($rounds * 3, $workflow->output());
        $this->assertSame(
            $rounds * 3,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCompleted)->count()
        );
        $this->assertSame($rounds, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
    }
}

final class ProjectionReplayWorkflow extends Workflow
{
    public function handle(int $rounds): int
    {
        $result = 0;

        for ($round = 0; $round < $rounds; $round++) {
            $result += array_sum(all([
                static fn (): mixed => activity(ProjectionReplayActivity::class, 1),
                static fn (): mixed => activity(ProjectionReplayActivity::class, 1),
                static fn (): mixed => activity(ProjectionReplayActivity::class, 1),
            ]));
            timer(1);
        }

        return $result;
    }
}

final class ProjectionReplayActivity extends Activity
{
    public function handle(int $value): int
    {
        return $value;
    }
}
