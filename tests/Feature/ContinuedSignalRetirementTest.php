<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\TestContinuedAwaitTimerWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Signal;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Timer;
use Workflow\WorkflowStub;

final class ContinuedSignalRetirementTest extends TestCase
{
    #[DataProvider('chainLengths')]
    public function testOldSignalsAndTimersRetireWhileTheCurrentRunStillWaits(int $length): void
    {
        $workflow = WorkflowStub::make(TestContinuedAwaitTimerWorkflow::class);
        $workflow->start(0, $length - 1);
        $current = StoredWorkflow::findOrFail($workflow->id());
        $continued = [];

        for ($step = 0; $step < $length; ++$step) {
            $this->waitForWorkflow(
                $workflow,
                static fn (): bool => $current->fresh()
                    ->status instanceof WorkflowWaitingStatus
                                        && $current->timers()
                                            ->count() === 1,
                'the current run to await its signal with a pending timer',
            );

            if ($step === $length - 1) {
                break;
            }

            $workflow->finish();
            $this->waitForWorkflow(
                $workflow,
                static fn (): bool => $current->fresh()
                    ->status instanceof WorkflowContinuedStatus
                                        && $current->continuedWorkflows()
                                            ->exists(),
                'the current run to durably continue',
            );
            $continued[] = $current;
            $current = $current->continuedWorkflows()
                ->sole();
        }

        $this->assertCount($length - 1, $continued);
        $this->assertTrue($workflow->running());
        foreach (array_reverse($continued) as $old) {
            $this->assertInstanceOf(WorkflowContinuedStatus::class, $old->fresh()->status);
            $timer = $old->timers()
                ->sole();
            $before = $old->logs()
                ->count();
            foreach ([new Signal($old), new Timer($old, $timer->index)] as $job) {
                $queueJob = Mockery::mock(Job::class);
                $queueJob->shouldNotReceive('release');
                $job->setJob($queueJob);
                $job->handle();
            }
            $this->assertSame($before, $old->logs()->count());
        }
        $this->assertInstanceOf(WorkflowWaitingStatus::class, $current->fresh()->status);
        $this->assertSame(1, $current->timers()->count());
        $workflow->finish();
        $this->waitForWorkflow($workflow);
        $this->assertSame('done', $workflow->output());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function chainLengths(): array
    {
        return [
            'two runs' => [2],
            'three runs with an intermediate continuation' => [3],
        ];
    }
}
