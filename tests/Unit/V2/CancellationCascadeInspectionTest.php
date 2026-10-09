<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CancellationCascadeView;

final class CancellationCascadeInspectionTest extends TestCase
{
    public function testAbsentAndInaccessibleCancellationInspectionsReturnNoView(): void
    {
        [$run] = $this->fixture(false);
        $this->assertNull($this->inspectWithoutWrites($run));
        $run->namespace = 'unavailable-namespace';
        $this->assertNull($this->inspectWithoutWrites($run));
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestEvidenceRemainsVisibleAndIncomplete(string $mutation, string $finding): void
    {
        [$run, $context, $request] = $this->fixture();
        $this->assertNotNull($request);
        $payload = $request->payload;
        switch ($mutation) {
            case 'missing-context':
                unset($payload['cancellation']);
                break;
            case 'non-array-lineage':
                $payload['cancellation']['lineage'] = 'unavailable';
                break;
            case 'long-lineage':
                $payload['cancellation']['lineage'] = array_fill(0, 21, $context->lineage[0]);
                break;
            case 'invalid-schema':
                $payload['cancellation']['schema'] = 'unsupported-cancellation-context';
                break;
            case 'command-mismatch':
                $payload['workflow_command_id'] = 'different-request';
                break;
            case 'target-mismatch':
                $payload['cancellation']['root_workflow_instance_id'] = 'different-instance';
                $payload['cancellation']['lineage'][0]['workflow_instance_id'] = 'different-instance';
                break;
            case 'duplicate':
                WorkflowHistoryEvent::record(
                    $run,
                    HistoryEventType::CooperativeCancellationRequested,
                    $payload,
                    command: $context->requestId
                );
                break;
        }
        $request->payload = $payload;
        $request->save();
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains($finding, array_column($view['findings'], 'code'));
        $this->assertSame($run->id, $view['selected_run_id']);
        $this->assertCount(1, $view['runs']);
        $this->assertNull($view['runs'][0]['cleanup']);
        if ($mutation === 'duplicate') {
            $this->assertSame($context->requestId, $view['root']['request_id']);
            $this->assertSame('requested', $view['runs'][0]['lifecycle']);
        } else {
            $this->assertNull($view['root']);
            $this->assertNull($view['runs'][0]['request']);
            $this->assertNull($view['runs'][0]['lifecycle']);
        }
    }

    #[DataProvider('terminalEvidence')]
    public function testTerminalHistoryRetainsItsLifecycleAndReportsConflictingProjection(
        HistoryEventType $eventType,
        RunStatus $status,
        string $lifecycle,
        bool $mismatch,
    ): void {
        [$run] = $this->fixture();
        $run->forceFill([
            'status' => $mismatch ? RunStatus::Running : $status,
            'closed_at' => now(),
        ])->save();
        $terminal = WorkflowHistoryEvent::record($run, $eventType);
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertSame(! $mismatch, $view['inspection_complete']);
        $this->assertSame($lifecycle, $view['runs'][0]['lifecycle']);
        $this->assertSame($terminal->id, $view['runs'][0]['terminal_history_event_id']);
        $this->assertSame($eventType->value, $view['runs'][0]['terminal_event_type']);
        $this->assertNull($view['runs'][0]['cleanup']);
        if ($mismatch) {
            $this->assertContains('terminal_projection_mismatch', array_column($view['findings'], 'code'));
        } else {
            $this->assertSame([], $view['findings']);
        }
    }

    #[DataProvider('invalidCleanup')]
    public function testInvalidCleanupCannotClaimVerifiedCompletion(string $mutation): void
    {
        [$run, $context] = $this->fixture();
        $run->forceFill([
            'status' => RunStatus::Cancelled,
            'closed_at' => now()
                ->addSecond(),
        ])->save();
        $cleanup = $this->cleanup($run, $context, 'not_delivered');
        switch ($mutation) {
            case 'request': $cleanup['request_id'] = 'different-request';
                break;
            case 'deadline': $cleanup['cleanup_deadline_at'] = now()->addMinute()->toISOString();
                break;
            case 'finish': $cleanup['finished_at'] = now()->toISOString();
                break;
            case 'outcome': $cleanup['outcome'] = 'unsupported-outcome';
                break;
            case 'missing-delivery': $cleanup['outcome'] = 'completed';
                break;
            case 'projected-delivery':
                $run->forceFill([
                    'cancellation_delivery_sequence' => 0,
                ])->save();
                break;
            case 'malformed-delivery':
                $run->forceFill([
                    'cancellation_delivery_sequence' => 0,
                ])->save();
                WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationDelivered, [
                    'workflow_command_id' => $context->requestId,
                    'sequence' => '0',
                ], command: $context->requestId);
                $cleanup['outcome'] = 'completed';
                break;
        }
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCancelled, [
            'cancellation_cleanup' => $cleanup,
        ]);
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains('cleanup_outcome_unavailable', array_column($view['findings'], 'code'));
        $this->assertSame('cancelled', $view['runs'][0]['lifecycle']);
        $this->assertNull($view['runs'][0]['cleanup']);
        $this->assertNull($view['runs'][0]['delivery']);
        $this->assertSame($context->requestId, $view['runs'][0]['request']['request_id']);
        if (str_contains($mutation, 'projected')) {
            $this->assertContains('delivery_history_unavailable', array_column($view['findings'], 'code'));
        } elseif ($mutation === 'malformed-delivery') {
            $this->assertContains('delivery_history_invalid', array_column($view['findings'], 'code'));
        }
    }

    #[DataProvider('validCleanup')]
    public function testValidCleanupPreservesTheRecordedOutcomeAndOriginalDeadline(string $outcome): void
    {
        [$run, $context] = $this->fixture();
        $run->forceFill([
            'status' => RunStatus::Cancelled,
            'closed_at' => $outcome === 'deadline_expired' ? $context->deadline() : now()
                ->addSecond(),
        ])->save();
        $cleanup = $this->cleanup($run, $context, $outcome);
        if ($outcome === 'completed') {
            $run->forceFill([
                'cancellation_delivery_sequence' => 0,
            ])->save();
            $delivery = WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationDelivered, [
                'workflow_command_id' => $context->requestId,
                'sequence' => 0,
                'sequence_span' => 1,
            ], command: $context->requestId);
            $cleanup['delivery_history_event_id'] = $delivery->id;
            $cleanup['delivery_sequence'] = 0;
        }
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCancelled, [
            'cancellation_cleanup' => $cleanup,
        ]);
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertTrue($view['inspection_complete']);
        $this->assertSame([], $view['findings']);
        $this->assertSame(
            $outcome === 'deadline_expired' ? 'deadline_expired' : 'cancelled',
            $view['runs'][0]['lifecycle']
        );
        $reported = $view['runs'][0]['cleanup'];
        $this->assertIsArray($reported);
        ksort($cleanup);
        ksort($reported);
        $this->assertSame($cleanup, $reported);
        $this->assertSame($context->deadline()->toISOString(), $view['root']['cleanup_deadline_at']);
    }

    public function testOversizedRequesterTextIsBoundedWithAnExplicitFinding(): void
    {
        [$run, $context, $request] = $this->fixture();
        $this->assertNotNull($request);
        $payload = $request->payload;
        $payload['cancellation']['requester']['label'] = str_repeat('é', 4097);
        $request->payload = $payload;
        $request->save();
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertTrue($view['truncated']);
        $this->assertContains('request_text_limit', array_column($view['findings'], 'code'));
        $this->assertSame(str_repeat('é', 4096), $view['root']['requester']['label']);
        $this->assertSame($context->requestId, $view['root']['request_id']);
    }

    public function testRecoveryEvidenceExposesOnlyItsDocumentedAttemptFields(): void
    {
        [$run] = $this->fixture();
        $recovery = [
            'original_workflow_task_id' => 'old-task',
            'original_workflow_task_attempt' => 2,
            'workflow_task_id' => 'new-task',
            'workflow_task_attempt' => 3,
            'lease_owner' => 'replacement-worker',
            'callback_stop_state' => 'unknown',
        ];
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => 'local-activity',
            'local_recovery' => [
                ...$recovery,
                'unpublished_detail' => 'omitted',
            ],
        ]);
        $view = $this->inspectWithoutWrites($run);
        $this->assertNotNull($view);
        $this->assertTrue($view['inspection_complete']);
        $this->assertCount(1, $view['runs'][0]['cleanup_recovery']);
        $reported = $view['runs'][0]['cleanup_recovery'][0];
        $this->assertSame($event->id, $reported['history_event_id']);
        $this->assertSame('local-activity', $reported['activity_execution_id']);
        $this->assertSame($event->recorded_at->toISOString(), $reported['recorded_at']);
        $attempt = $reported['attempt'];
        $this->assertIsArray($attempt);
        ksort($recovery);
        ksort($attempt);
        $this->assertSame($recovery, $attempt);
        $this->assertSame('requested', $view['runs'][0]['lifecycle']);
    }

    public static function invalidRequests(): array
    {
        return array_map(static fn (string $mutation, string $finding): array => [$mutation, $finding], [
            'missing-context', 'non-array-lineage', 'long-lineage', 'invalid-schema',
            'command-mismatch', 'target-mismatch', 'duplicate',
        ], [
            'request_context_unavailable', 'request_context_unavailable', 'request_context_unavailable',
            'request_context_invalid', 'request_context_invalid', 'request_context_invalid', 'request_history_conflict',
        ]);
    }

    public static function terminalEvidence(): array
    {
        $cases = [];
        foreach ([[HistoryEventType::WorkflowTerminated, RunStatus::Terminated, 'terminated'],
            [HistoryEventType::WorkflowFailed, RunStatus::Failed, 'failed'],
            [HistoryEventType::WorkflowTimedOut, RunStatus::Failed, 'timed_out'],
            [HistoryEventType::WorkflowCompleted, RunStatus::Completed, 'completed']] as $terminal) {
            foreach ([false, true] as $mismatch) {
                $cases[] = [...$terminal, $mismatch];
            }
        }
        return $cases;
    }

    public static function invalidCleanup(): array
    {
        return array_map(static fn (string $mutation): array => [$mutation], [
            'request', 'deadline', 'finish', 'outcome', 'missing-delivery', 'projected-delivery', 'malformed-delivery',
        ]);
    }

    public static function validCleanup(): array
    {
        return [['completed'], ['not_delivered'], ['deadline_expired']];
    }

    /**
     * @return array{WorkflowRun, CancellationContext, WorkflowHistoryEvent|null}
     */
    private function fixture(bool $requested = true): array
    {
        Carbon::setTestNow('2026-10-09T00:00:00Z');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });
        $instance = WorkflowInstance::query()->create([
            'id' => 'cancellation-inspection-' . Str::ulid(),
            'workflow_class' => 'App\\Workflows\\InspectionWorkflow',
            'workflow_type' => 'cancellation.inspection',
            'namespace' => 'inspection-tests',
            'run_count' => 1,
        ]);
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => $instance->namespace,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $id = (string) Str::ulid();
        $context = CancellationContext::fromArray([
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => $id,
            'root_request_id' => $id,
            'root_workflow_instance_id' => $instance->id,
            'root_workflow_run_id' => $run->id,
            'parent_request_id' => null,
            'reason' => 'maintenance',
            'requester' => [
                'type' => 'operator',
                'id' => 'test-operator',
            ],
            'source' => 'operator',
            'requested_at' => now()
                ->toISOString(),
            'cleanup_deadline_at' => now()
                ->addSeconds(30)
                ->toISOString(),
            'lineage' => [[
                'request_id' => $id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ]],
        ]);
        $event = null;
        if ($requested) {
            $run->forceFill([
                'cancellation_request_command_id' => $id,
                'cancellation_requested_at' => now(),
                'cancellation_deadline_at' => $context->deadline(),
            ])->save();
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationRequested, [
                'workflow_command_id' => $id,
                'cancellation' => $context->toArray(),
            ], command: $id);
        }
        return [$run, $context, $event];
    }

    private function cleanup(WorkflowRun $run, CancellationContext $context, string $outcome): array
    {
        return [
            'request_id' => $context->requestId,
            'outcome' => $outcome,
            'cleanup_deadline_at' => $context->deadline()
                ->toISOString(),
            'finished_at' => $run->closed_at->toISOString(),
        ];
    }

    private function inspectWithoutWrites(WorkflowRun $run): ?array
    {
        $before = $this->snapshot();
        $attributes = $run->getAttributes();
        $first = CancellationCascadeView::forRun($run);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($attributes, $run->getAttributes());
        Carbon::setTestNow(now()->addDay());
        $this->assertSame($first, CancellationCascadeView::forRun($run));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($attributes, $run->getAttributes());
        return $first;
    }

    private function snapshot(): array
    {
        $state = [];
        foreach ([
            'workflow_instances',
            'workflow_runs',
            'workflow_history_events',
            'workflow_links',
            'workflow_child_calls',
        ] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(
                static fn (object $row): array => (array) $row
            )->all();
        }
        return $state;
    }
}
