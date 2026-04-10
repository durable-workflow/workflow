<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestReplayedDomainException;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\HistoryTimeline;

final class V2BackfillFailureTypesCommandTest extends TestCase
{
    public function testItBackfillsDurableExceptionTypesForLegacyFailureHistory(): void
    {
        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);
        config()
            ->set('workflows.v2.types.exception_class_aliases', [
                'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
            ]);

        [$run, $event] = $this->createLegacyFailureEvent('failure-type-backfill');

        $this->artisan('workflow:v2:backfill-failure-types', [
            '--run-id' => [$run->id],
        ])
            ->expectsOutput('Backfilled 1 failure history event(s).')
            ->assertSuccessful();

        $event->refresh();
        $payload = $event->payload;

        $this->assertSame('order-rejected', $payload['exception_type'] ?? null);
        $this->assertSame('order-rejected', $payload['exception']['type'] ?? null);

        $snapshot = FailureSnapshots::forRun($run->refresh())[0];

        $this->assertSame('order-rejected', $snapshot['exception_type']);
        $this->assertSame(TestReplayedDomainException::class, $snapshot['exception_resolved_class']);
        $this->assertSame('exception_type', $snapshot['exception_resolution_source']);
    }

    public function testDryRunReportsMappableRowsWithoutMutatingHistory(): void
    {
        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);
        config()
            ->set('workflows.v2.types.exception_class_aliases', [
                'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
            ]);

        [$run, $event] = $this->createLegacyFailureEvent('failure-type-dry-run');

        $this->artisan('workflow:v2:backfill-failure-types', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
        ])
            ->expectsOutput('Would backfill 1 failure history event(s).')
            ->assertSuccessful();

        $event->refresh();
        $payload = $event->payload;

        $this->assertArrayNotHasKey('exception_type', $payload);
        $this->assertArrayNotHasKey('type', $payload['exception']);
    }

    public function testItBackfillsDurableExceptionTypesForHandledFailureHistory(): void
    {
        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);
        config()
            ->set('workflows.v2.types.exception_class_aliases', [
                'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
            ]);

        [$run, $failureEvent] = $this->createLegacyFailureEvent('failure-type-handled-backfill');

        $handledEvent = WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::FailureHandled, [
            'failure_id' => $failureEvent->payload['failure_id'],
            'sequence' => 1,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-1',
            'propagation_kind' => 'activity',
            'exception_class' => 'App\\Legacy\\OrderRejected',
            'message' => 'Order order-123 rejected via api',
            'handled' => true,
        ]);

        $this->artisan('workflow:v2:backfill-failure-types', [
            '--run-id' => [$run->id],
        ])
            ->expectsOutput('Backfilled 2 failure history event(s).')
            ->assertSuccessful();

        $failurePayload = $failureEvent->refresh()->payload;
        $handledPayload = $handledEvent->refresh()->payload;

        $this->assertSame('order-rejected', $failurePayload['exception_type'] ?? null);
        $this->assertSame('order-rejected', $failurePayload['exception']['type'] ?? null);
        $this->assertSame('order-rejected', $handledPayload['exception_type'] ?? null);

        $handledTimelineEntry = collect(HistoryTimeline::forRun($run->refresh()))
            ->firstWhere('type', HistoryEventType::FailureHandled->value);

        $this->assertIsArray($handledTimelineEntry);
        $this->assertSame('order-rejected', $handledTimelineEntry['failure']['exception_type'] ?? null);
        $this->assertTrue($handledTimelineEntry['failure']['handled'] ?? false);
    }

    public function testStrictModeFailsWhenLegacyFailureHistoryCannotBeMapped(): void
    {
        [$run, $event] = $this->createLegacyFailureEvent('failure-type-unresolved');

        $this->artisan('workflow:v2:backfill-failure-types', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
            '--strict' => true,
        ])
            ->expectsOutput('Would backfill 0 failure history event(s).')
            ->expectsOutput('Unresolved 1 failure history event(s).')
            ->assertFailed();

        $event->refresh();
        $payload = $event->payload;

        $this->assertArrayNotHasKey('exception_type', $payload);
        $this->assertArrayNotHasKey('type', $payload['exception']);
    }

    public function testStrictModeIgnoresSuccessfulUpdateCompletedHistory(): void
    {
        $run = $this->createRun('failure-type-successful-update');

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'update_id' => (string) Str::ulid(),
            'outcome' => 'update_completed',
        ]);

        $this->artisan('workflow:v2:backfill-failure-types', [
            '--run-id' => [$run->id],
            '--dry-run' => true,
            '--strict' => true,
        ])
            ->expectsOutput('Would backfill 0 failure history event(s).')
            ->assertSuccessful();
    }

    /**
     * @return array{WorkflowRun, WorkflowHistoryEvent}
     */
    private function createLegacyFailureEvent(string $instanceId): array
    {
        $run = $this->createRun($instanceId);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-1',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => 'App\\Legacy\\OrderRejected',
            'message' => 'Order order-123 rejected via api',
            'file' => __FILE__,
            'line' => 123,
            'trace_preview' => '',
        ]);

        $event = WorkflowHistoryEvent::record(
            $run->refresh(),
            HistoryEventType::ActivityFailed,
            [
                'failure_id' => $failure->id,
                'activity_execution_id' => 'activity-1',
                'exception_class' => 'App\\Legacy\\OrderRejected',
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
                'exception' => [
                    'class' => 'App\\Legacy\\OrderRejected',
                    'message' => 'Order order-123 rejected via api',
                    'code' => 422,
                    'file' => __FILE__,
                    'line' => 123,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
        );

        return [$run->refresh(), $event->refresh()];
    }

    private function createRun(string $instanceId): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\FailureBackfillWorkflow',
            'workflow_type' => 'failure.backfill',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\FailureBackfillWorkflow',
            'workflow_type' => 'failure.backfill',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run->refresh();
    }
}
