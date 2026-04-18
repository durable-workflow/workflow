<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestFailingActivity;
use Tests\Fixtures\V2\TestSagaBookingActivity;
use Tests\Fixtures\V2\TestSagaCancelActivity;
use Tests\Fixtures\V2\TestSagaContinueWithErrorWorkflow;
use Tests\Fixtures\V2\TestSagaFailingCancelActivity;
use Tests\Fixtures\V2\TestSagaParallelCompensationWorkflow;
use Tests\Fixtures\V2\TestSagaParallelContinueWithErrorWorkflow;
use Tests\Fixtures\V2\TestSagaParallelFailingCompensationWorkflow;
use Tests\Fixtures\V2\TestSagaSuccessWorkflow;
use Tests\Fixtures\V2\TestSagaWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\WorkflowStub;

final class V2SagaWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        TestSagaBookingActivity::resetLog();
        TestSagaCancelActivity::resetLog();
        TestSagaFailingCancelActivity::resetLog();
    }

    public function testSagaCompensatesInReverseOrderOnFailure(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaWorkflow::class, 'saga-reverse-order');
        $workflow->start(true);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertTrue($output['compensated']);
        $this->assertSame('payment failed', $output['reason']);

        // Compensations run in reverse registration order: hotel first, then flight.
        $this->assertSame(['hotel:hotel-id-2', 'flight:flight-id-1'], $cancelLog);

        WorkflowStub::assertDispatchedTimes(TestSagaBookingActivity::class, 2);
        WorkflowStub::assertDispatchedTimes(TestSagaCancelActivity::class, 2);
    }

    public function testSagaSkipsCompensationOnSuccess(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaSuccessWorkflow::class, 'saga-success');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertSame('flight-id-1', $output['flight']);
        $this->assertSame('hotel-id-2', $output['hotel']);

        // No compensation activities should run when the workflow succeeds.
        $this->assertSame([], $cancelLog);
        WorkflowStub::assertNotDispatched(TestSagaCancelActivity::class);
    }

    public function testSagaParallelCompensationRunsAllCompensationsViaAll(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaParallelCompensationWorkflow::class, 'saga-parallel');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertTrue($output['compensated']);
        $this->assertSame('payment failed', $output['reason']);

        // Both compensations should run (order within parallel may vary).
        $this->assertCount(2, $cancelLog);
        $this->assertContains('hotel:hotel-id-2', $cancelLog);
        $this->assertContains('flight:flight-id-1', $cancelLog);
    }

    public function testSagaContinueWithErrorRunsAllCompensationsEvenWhenOneFails(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        WorkflowStub::mock(
            TestSagaFailingCancelActivity::class,
            static function ($ctx, string $service, string $bookingId): never {
                throw new RuntimeException("Cancel failed for {$service}");
            }
        );

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaContinueWithErrorWorkflow::class, 'saga-continue-error');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertTrue($output['compensated']);

        // Hotel cancel should still run even though the flight cancel (TestSagaFailingCancelActivity) throws.
        // Compensation order: hotel first (reverse), then flight.
        $this->assertSame(['hotel:hotel-id-2'], $cancelLog);
    }

    public function testSagaCompensationProducesActivityHistoryEvents(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        WorkflowStub::mock(
            TestSagaCancelActivity::class,
            static fn ($ctx, string $service, string $bookingId): string => "cancelled-{$bookingId}"
        );

        $workflow = WorkflowStub::make(TestSagaWorkflow::class, 'saga-history');
        $workflow->start(true);

        $this->assertTrue($workflow->refresh()->completed());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        // Expected flow:
        // StartAccepted, WorkflowStarted,
        // ActivityScheduled (book flight), ActivityStarted, ActivityCompleted,
        // ActivityScheduled (book hotel), ActivityStarted, ActivityCompleted,
        // ActivityScheduled (failing), ActivityStarted, ActivityFailed,
        // ActivityScheduled (cancel hotel), ActivityStarted, ActivityCompleted,
        // ActivityScheduled (cancel flight), ActivityStarted, ActivityCompleted,
        // WorkflowCompleted
        $this->assertContains(HistoryEventType::WorkflowStarted->value, $events);
        $this->assertContains(HistoryEventType::WorkflowCompleted->value, $events);

        $scheduledCount = array_count_values($events)[HistoryEventType::ActivityScheduled
->value] ?? 0;
        // 2 bookings + 1 failing + 2 cancellations = 5 activity schedules
        $this->assertSame(5, $scheduledCount);

        $completedCount = array_count_values($events)[HistoryEventType::ActivityCompleted
->value] ?? 0;
        // 2 bookings + 2 cancellations = 4 completions
        $this->assertSame(4, $completedCount);

        $failedCount = array_count_values($events)[HistoryEventType::ActivityFailed
->value] ?? 0;
        // 1 failing activity
        $this->assertSame(1, $failedCount);
    }

    public function testSagaParallelContinueWithErrorRunsAllCompensationsWhenOneFails(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        WorkflowStub::mock(
            TestSagaFailingCancelActivity::class,
            static function ($ctx, string $service, string $bookingId): never {
                throw new RuntimeException("Cancel failed for {$service}");
            }
        );

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaParallelContinueWithErrorWorkflow::class, 'saga-parallel-continue');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertTrue($output['compensated']);
        $this->assertSame('payment failed', $output['reason']);

        // Hotel cancel should run even though flight cancel (TestSagaFailingCancelActivity) throws.
        // Both compensations are dispatched in parallel; continueWithError swallows the failure.
        $this->assertSame(['hotel:hotel-id-2'], $cancelLog);

        WorkflowStub::assertDispatchedTimes(TestSagaFailingCancelActivity::class, 1);
        WorkflowStub::assertDispatchedTimes(TestSagaCancelActivity::class, 1);
    }

    public function testSagaParallelWithoutContinueWithErrorPropagatesCompensationFailure(): void
    {
        WorkflowStub::fake();

        $bookingSequence = 0;
        WorkflowStub::mock(TestSagaBookingActivity::class, static function ($ctx, string $service) use (
            &$bookingSequence
        ): string {
            $bookingSequence++;

            return "{$service}-id-{$bookingSequence}";
        });

        WorkflowStub::mock(TestFailingActivity::class, static function (): never {
            throw new RuntimeException('payment failed');
        });

        WorkflowStub::mock(
            TestSagaFailingCancelActivity::class,
            static function ($ctx, string $service, string $bookingId): never {
                throw new RuntimeException("Cancel failed for {$service}");
            }
        );

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        $workflow = WorkflowStub::make(TestSagaParallelFailingCompensationWorkflow::class, 'saga-parallel-fail');
        $workflow->start();

        // Without continueWithError, the compensation failure propagates and the workflow fails.
        $this->assertTrue($workflow->refresh()->failed());
    }

    public function testSagaWithoutFailureDoesNotTriggerCompensation(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(
            TestSagaBookingActivity::class,
            static fn ($ctx, string $service): string => "{$service}-ok"
        );
        WorkflowStub::mock(TestFailingActivity::class, static fn ($ctx): string => 'did-not-fail');

        $cancelLog = [];
        WorkflowStub::mock(TestSagaCancelActivity::class, static function ($ctx, string $service, string $bookingId) use (
            &$cancelLog
        ): string {
            $cancelLog[] = "{$service}:{$bookingId}";

            return "cancelled-{$bookingId}";
        });

        // Pass false to skip the failing activity.
        $workflow = WorkflowStub::make(TestSagaWorkflow::class, 'saga-no-failure');
        $workflow->start(false);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertArrayHasKey('flight', $output);
        $this->assertArrayHasKey('hotel', $output);
        $this->assertArrayHasKey('car', $output);
        $this->assertArrayNotHasKey('compensated', $output);

        $this->assertSame([], $cancelLog);
        WorkflowStub::assertNotDispatched(TestSagaCancelActivity::class);
    }
}
