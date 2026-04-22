<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryBudget;

final class HistoryBudgetTest extends TestCase
{
    // ---------------------------------------------------------------
    //  Default thresholds
    // ---------------------------------------------------------------

    public function testDefaultEventThreshold(): void
    {
        $this->assertSame(10000, HistoryBudget::eventThreshold());
    }

    public function testDefaultSizeBytesThreshold(): void
    {
        $this->assertSame(5242880, HistoryBudget::sizeBytesThreshold());
    }

    // ---------------------------------------------------------------
    //  Configurable thresholds
    // ---------------------------------------------------------------

    public function testCustomEventThresholdFromConfig(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 500);

        $this->assertSame(500, HistoryBudget::eventThreshold());
    }

    public function testCustomSizeBytesThresholdFromConfig(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1048576);

        $this->assertSame(1048576, HistoryBudget::sizeBytesThreshold());
    }

    public function testNegativeThresholdClampedToZero(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', -100);

        $this->assertSame(0, HistoryBudget::eventThreshold());
    }

    public function testNonNumericThresholdFallsBackToDefault(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 'invalid');

        $this->assertSame(10000, HistoryBudget::eventThreshold());
    }

    // ---------------------------------------------------------------
    //  Budget computation
    // ---------------------------------------------------------------

    public function testEmptyRunReturnsZeroBudgetAndNoContinueAsNew(): void
    {
        $run = $this->createRunWithEvents(0);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(0, $budget['history_event_count']);
        $this->assertSame(0, $budget['history_size_bytes']);
        $this->assertFalse($budget['continue_as_new_recommended']);
    }

    public function testSmallRunDoesNotRecommendContinueAsNew(): void
    {
        $run = $this->createRunWithEvents(5);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(5, $budget['history_event_count']);
        $this->assertGreaterThan(0, $budget['history_size_bytes']);
        $this->assertFalse($budget['continue_as_new_recommended']);
    }

    public function testRunAtEventThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);

        $run = $this->createRunWithEvents(3);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(3, $budget['history_event_count']);
        $this->assertTrue($budget['continue_as_new_recommended']);
    }

    public function testRunAboveEventThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);

        $run = $this->createRunWithEvents(5);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(5, $budget['history_event_count']);
        $this->assertTrue($budget['continue_as_new_recommended']);
    }

    public function testRunAtSizeThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1);

        $run = $this->createRunWithEvents(1);

        $budget = HistoryBudget::forRun($run);

        $this->assertTrue($budget['continue_as_new_recommended']);
    }

    public function testDisabledThresholdsNeverRecommendContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 0);

        $run = $this->createRunWithEvents(100);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(100, $budget['history_event_count']);
        $this->assertFalse($budget['continue_as_new_recommended']);
    }

    public function testBudgetCountsAllHistoryEventTypes(): void
    {
        $instance = WorkflowInstance::create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'running',
        ]);

        $eventTypes = [
            HistoryEventType::WorkflowStarted,
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired,
        ];

        $sequence = 1;
        foreach ($eventTypes as $eventType) {
            $payload = match ($eventType) {
                HistoryEventType::WorkflowStarted => [
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'workflow_type' => $run->workflow_type,
                ],
                HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted => [
                    'sequence' => $sequence,
                    'activity_type' => 'history.budget',
                ],
                HistoryEventType::ActivityCompleted => [
                    'sequence' => $sequence,
                    'activity_type' => 'history.budget',
                    'result' => true,
                ],
                HistoryEventType::TimerScheduled, HistoryEventType::TimerFired => [
                    'sequence' => $sequence,
                ],
                default => [
                    'sequence' => $sequence,
                ],
            };

            WorkflowHistoryEvent::record($run, $eventType, $payload);
            $sequence++;
        }

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(count($eventTypes), $budget['history_event_count']);
        $this->assertGreaterThan(0, $budget['history_size_bytes']);
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    private function createRunWithEvents(int $eventCount): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'running',
        ]);

        for ($i = 0; $i < $eventCount; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
                'sequence' => $i + 1,
                'activity_type' => 'history.budget',
                'result' => str_repeat('x', 50),
            ]);
        }

        return $run;
    }
}
