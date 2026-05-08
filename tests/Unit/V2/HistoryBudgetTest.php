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
        $this->assertSame(10000, HistoryBudget::eventHardThreshold());
    }

    public function testDefaultSizeBytesThreshold(): void
    {
        $this->assertSame(5242880, HistoryBudget::sizeBytesThreshold());
        $this->assertSame(5242880, HistoryBudget::sizeBytesHardThreshold());
    }

    public function testDefaultFanOutThreshold(): void
    {
        $this->assertSame(200, HistoryBudget::fanOutHardThreshold());
        $this->assertSame(160, HistoryBudget::fanOutWarningThreshold());
    }

    public function testDefaultWarningThresholdsSitBelowHardThresholds(): void
    {
        $this->assertSame(8000, HistoryBudget::eventWarningThreshold());
        $this->assertSame(4194304, HistoryBudget::sizeBytesWarningThreshold());
        $this->assertLessThan(HistoryBudget::eventHardThreshold(), HistoryBudget::eventWarningThreshold());
        $this->assertLessThan(HistoryBudget::sizeBytesHardThreshold(), HistoryBudget::sizeBytesWarningThreshold());
        $this->assertLessThan(HistoryBudget::fanOutHardThreshold(), HistoryBudget::fanOutWarningThreshold());
    }

    // ---------------------------------------------------------------
    //  Configurable thresholds
    // ---------------------------------------------------------------

    public function testCustomEventThresholdFromConfig(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 500);

        $this->assertSame(500, HistoryBudget::eventThreshold());
        $this->assertSame(500, HistoryBudget::eventHardThreshold());
    }

    public function testCustomSizeBytesThresholdFromConfig(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1048576);

        $this->assertSame(1048576, HistoryBudget::sizeBytesThreshold());
    }

    public function testCustomFanOutThresholdFromConfig(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_fan_out_threshold', 50);
        config()->set('workflows.v2.history_budget.fan_out_warning_threshold', 30);

        $this->assertSame(50, HistoryBudget::fanOutHardThreshold());
        $this->assertSame(30, HistoryBudget::fanOutWarningThreshold());
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

    public function testWarningThresholdClampsToHardThreshold(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 100);
        config()->set('workflows.v2.history_budget.event_warning_threshold', 5000);

        $this->assertSame(100, HistoryBudget::eventWarningThreshold());
    }

    public function testWarningThresholdRespectsExplicitZero(): void
    {
        config()->set('workflows.v2.history_budget.event_warning_threshold', 0);

        $this->assertSame(0, HistoryBudget::eventWarningThreshold());
    }

    // ---------------------------------------------------------------
    //  Budget computation
    // ---------------------------------------------------------------

    public function testEmptyRunReturnsZeroBudgetAndOkPressure(): void
    {
        $run = $this->createRunWithEvents(0);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(0, $budget['history_event_count']);
        $this->assertSame(0, $budget['history_size_bytes']);
        $this->assertSame(0, $budget['history_fan_out']);
        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_OK, $budget['pressure']);
        $this->assertSame([], $budget['pressure_dimensions']);
    }

    public function testSmallRunStaysOk(): void
    {
        $run = $this->createRunWithEvents(5);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(5, $budget['history_event_count']);
        $this->assertGreaterThan(0, $budget['history_size_bytes']);
        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_OK, $budget['pressure']);
    }

    public function testRunBetweenWarnAndHardReportsApproaching(): void
    {
        config()->set('workflows.v2.history_budget.event_warning_threshold', 3);
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 10);

        $run = $this->createRunWithEvents(4);

        $budget = HistoryBudget::forRun($run);

        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_APPROACHING, $budget['pressure']);
        $this->assertSame(
            [HistoryBudget::DIMENSION_EVENT_COUNT],
            $budget['pressure_dimensions'],
        );
    }

    public function testRunAtEventHardThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);

        $run = $this->createRunWithEvents(3);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(3, $budget['history_event_count']);
        $this->assertTrue($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED, $budget['pressure']);
        $this->assertContains(HistoryBudget::DIMENSION_EVENT_COUNT, $budget['pressure_dimensions']);
    }

    public function testRunAboveEventHardThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);

        $run = $this->createRunWithEvents(5);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(5, $budget['history_event_count']);
        $this->assertTrue($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED, $budget['pressure']);
    }

    public function testRunAtSizeHardThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1);

        $run = $this->createRunWithEvents(1);

        $budget = HistoryBudget::forRun($run);

        $this->assertTrue($budget['continue_as_new_recommended']);
        $this->assertContains(HistoryBudget::DIMENSION_SIZE_BYTES, $budget['pressure_dimensions']);
    }

    public function testDisabledThresholdsNeverRecommendContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 0);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_fan_out_threshold', 0);
        config()->set('workflows.v2.history_budget.event_warning_threshold', 0);
        config()->set('workflows.v2.history_budget.size_bytes_warning_threshold', 0);
        config()->set('workflows.v2.history_budget.fan_out_warning_threshold', 0);

        $run = $this->createRunWithEvents(100);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(100, $budget['history_event_count']);
        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_OK, $budget['pressure']);
    }

    public function testBudgetCountsAllHistoryEventTypes(): void
    {
        $run = $this->createRun();

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
    //  Fan-out
    // ---------------------------------------------------------------

    public function testFanOutPicksMaxParallelGroupSize(): void
    {
        $run = $this->createRun();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 1,
            'activity_type' => 'fan.out',
            'parallel_group_id' => 'group-a',
            'parallel_group_kind' => 'all',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 4,
            'parallel_group_index' => 0,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 2,
            'activity_type' => 'fan.out',
            'parallel_group_id' => 'group-a',
            'parallel_group_kind' => 'all',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 4,
            'parallel_group_index' => 1,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 3,
            'activity_type' => 'fan.out',
            'parallel_group_id' => 'group-b',
            'parallel_group_kind' => 'all',
            'parallel_group_base_sequence' => 3,
            'parallel_group_size' => 12,
            'parallel_group_index' => 0,
        ]);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(12, $budget['history_fan_out']);
    }

    public function testFanOutAtHardThresholdRecommendsContinueAsNew(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 0);
        config()->set('workflows.v2.history_budget.continue_as_new_fan_out_threshold', 4);

        $run = $this->createRun();
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 1,
            'activity_type' => 'fan.out',
            'parallel_group_id' => 'group-a',
            'parallel_group_kind' => 'all',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 4,
            'parallel_group_index' => 0,
        ]);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(4, $budget['history_fan_out']);
        $this->assertTrue($budget['continue_as_new_recommended']);
        $this->assertContains(HistoryBudget::DIMENSION_FAN_OUT, $budget['pressure_dimensions']);
    }

    public function testFanOutBetweenWarnAndHardReportsApproaching(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 0);
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 0);
        config()->set('workflows.v2.history_budget.continue_as_new_fan_out_threshold', 10);
        config()->set('workflows.v2.history_budget.fan_out_warning_threshold', 4);

        $run = $this->createRun();
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 1,
            'activity_type' => 'fan.out',
            'parallel_group_id' => 'group-a',
            'parallel_group_kind' => 'all',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 6,
            'parallel_group_index' => 0,
        ]);

        $budget = HistoryBudget::forRun($run);

        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_APPROACHING, $budget['pressure']);
        $this->assertSame(
            [HistoryBudget::DIMENSION_FAN_OUT],
            $budget['pressure_dimensions'],
        );
    }

    public function testEventsWithoutParallelGroupHaveZeroFanOut(): void
    {
        $run = $this->createRunWithEvents(3);

        $budget = HistoryBudget::forRun($run);

        $this->assertSame(0, $budget['history_fan_out']);
    }

    // ---------------------------------------------------------------
    //  fromCounters
    // ---------------------------------------------------------------

    public function testFromCountersDerivesPressureWithoutLoadingHistory(): void
    {
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 100);
        config()->set('workflows.v2.history_budget.event_warning_threshold', 80);

        $budget = HistoryBudget::fromCounters(85, 1024, 0);

        $this->assertSame(85, $budget['history_event_count']);
        $this->assertSame(1024, $budget['history_size_bytes']);
        $this->assertSame(0, $budget['history_fan_out']);
        $this->assertFalse($budget['continue_as_new_recommended']);
        $this->assertSame(HistoryBudget::PRESSURE_APPROACHING, $budget['pressure']);
    }

    public function testFromCountersClampsNegativeValuesToZero(): void
    {
        $budget = HistoryBudget::fromCounters(-10, -200, -5);

        $this->assertSame(0, $budget['history_event_count']);
        $this->assertSame(0, $budget['history_size_bytes']);
        $this->assertSame(0, $budget['history_fan_out']);
        $this->assertSame(HistoryBudget::PRESSURE_OK, $budget['pressure']);
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    private function createRunWithEvents(int $eventCount): WorkflowRun
    {
        $run = $this->createRun();

        for ($i = 0; $i < $eventCount; $i++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
                'sequence' => $i + 1,
                'activity_type' => 'history.budget',
                'result' => str_repeat('x', 50),
            ]);
        }

        return $run;
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);

        return WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'running',
        ]);
    }
}
