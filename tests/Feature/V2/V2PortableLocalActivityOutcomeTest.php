<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    public function testSuccessUsesThePreparedAttemptPreservesAvroBytesAndRetainsTheClaimForReplay(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $before = $task->getAttributes();
        $report = $this->success();
        $reply = $this->finish($prepared, $report);
        $this->assertTrue($reply['recorded']);
        $this->assertFalse($reply['duplicate']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame([], $reply['created_task_ids']);
        $this->assertSame($prepared['activity_attempt_id'], $reply['activity_attempt_id']);
        $this->assertSame('sdk-local-attempt', $reply['worker_attempt_id']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $this->assertSame(ActivityStatus::Completed, $execution->status);
        $this->assertSame($report['result'], $execution->result);
        $this->assertSame([
            'value' => 'Hello, Taylor!',
        ], $execution->activityResult());
        $this->assertSame($prepared['activity_attempt_id'], $execution->current_attempt_id);
        $this->assertSame(1, $execution->attempts()->count());
        $this->assertSame(ActivityAttemptStatus::Completed, $execution->attempts()->sole()->status);
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $this->assertSame(2, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame($report['result'], $event->payload['result']);
        $this->assertSame($task->id, $event->payload['activity_attempt']['task_id']);
        $this->assertSame('sdk-local-attempt', $event->payload['activity_attempt']['worker_attempt_id']);
        foreach (HistoryTimeline::forRun($run->fresh()) as $entry) {
            $this->assertSame('workflow', $entry['task']['type']);
            $this->assertSame(2, $entry['task']['attempt_count']);
            $this->assertSame($entry['type'] === 'ActivityScheduled' ? 0 : 1, $entry['activity']['attempt_count']);
        }
        $count = WorkflowHistoryEvent::query()->count();
        // This opaque alias has no PHP class. Replay must find the recorded
        // result before any application/type admission or callback invocation.
        $replayed = app(LocalActivityExecutor::class)->execute(
            $run->fresh(),
            $task->fresh(),
            1,
            new LocalActivityCall('python-local-opaque', ['Taylor'])
        );
        $this->assertSame('completed', $replayed['status']);
        $this->assertSame($reply['event_id'], $replayed['event']->id);
        $this->assertSame($count, WorkflowHistoryEvent::query()->count());
    }

    public function testResponseLossReturnsTheOriginalOutcomeAfterClaimTakeoverAndLeaseExpiry(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $first = $this->finish($prepared, $this->success());
        $this->assertTrue($first['recorded']);
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 3,
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $count = $run->historyEvents()
            ->count();
        $second = $this->finish($prepared, $this->success());
        $this->assertSame([
            ...$first,
            'duplicate' => true,
        ], $second);
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            'local_activity_preparation_mismatch',
            app(LocalActivityExecutor::class)->recordPortableOutcome(
                $prepared['activity_attempt_id'],
                'replacement',
                3,
                $this->success(),
                '1.20',
            )['reason']
        );
    }

    public function testAChangedOutcomeCannotRelabelTheOriginalReceipt(): void
    {
        [$run, , $prepared] = $this->preparedClaim();
        $this->assertTrue($this->finish($prepared, $this->success())['recorded']);
        $count = $run->historyEvents()
            ->count();
        $changed = $this->success();
        $changed['result'] = Serializer::serializeWithCodec('avro', 'different');
        $this->assertSame('local_activity_outcome_mismatch', $this->finish($prepared, $changed)['reason']);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    public function testTypedNonRetryableFailureUsesTheExistingFailureRecorderAndRetainsTheClaim(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim([
            'retry_policy' => [
                'max_attempts' => 3,
            ],
        ]);
        $before = $task->getAttributes();
        $report = [
            'outcome' => 'failed',
            'message' => 'cannot continue',
            'exception_type' => 'python.Fatal',
            'non_retryable' => true,
        ];
        $reply = $this->finish($prepared, $report);
        $this->assertTrue($reply['recorded']);
        $this->assertSame('ActivityFailed', $reply['event_type']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $failure = $run->failures()
            ->sole();
        $this->assertSame('python.Fatal', $failure->exception_class);
        $this->assertSame('cannot continue', $failure->message);
        $this->assertTrue($failure->non_retryable);
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->finish($prepared, $report));
        $this->assertSame(1, $run->failures()->count());
    }

    public function testRetryRecordsOneDurableWorkflowRetryAndReleasesTheOriginalClaimAtomically(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim([
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [3],
            ],
            'schedule_to_close_timeout' => 30,
        ]);
        $report = [
            'outcome' => 'failed',
            'message' => 'temporary',
            'exception_type' => 'rust.Temporary',
        ];
        $reply = $this->finish($prepared, $report);
        $this->assertTrue($reply['recorded']);
        $this->assertTrue($reply['claim_released']);
        $this->assertSame('ActivityRetryScheduled', $reply['event_type']);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertNull($task->lease_expires_at);
        $this->assertCount(1, $reply['created_task_ids']);
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        $this->assertSame(TaskType::Workflow, $retry->task_type);
        $this->assertSame(TaskStatus::Ready, $retry->status);
        $this->assertSame($prepared['activity_attempt_id'], $retry->payload['retry_after_attempt_id']);
        $this->assertSame(3, $retry->payload['retry_backoff_seconds']);
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(
            $prepared['schedule_to_close_deadline_at'],
            $execution->schedule_to_close_deadline_at->toISOString()
        );
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->finish($prepared, $report));
        $this->assertSame(2, $run->tasks()->count());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count()
        );
    }

    public function testReclaimedOrExpiredClaimsCannotPublishANewResult(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 3,
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $this->assertSame('workflow_claim_mismatch', $this->finish($prepared, $this->success())['reason']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $task->forceFill([
            'lease_owner' => 'portable-worker',
            'attempt_count' => 2,
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $this->assertSame('workflow_claim_expired', $this->finish($prepared, $this->success())['reason']);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
    }

    public function testCancellationFencesTheOriginalAttemptAfterTakeoverWithoutPretendingTheCallbackStopped(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->requestCancellation('maintenance', 30)->accepted()
        );
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 3,
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $reply = $this->finish($prepared, $this->success());
        $this->assertFalse($reply['recorded']);
        $this->assertSame('cancellation_requested', $reply['reason']);
        $this->assertTrue($reply['fenced']);
        $this->assertIsString($reply['cancellation_history_event_id']);
        $this->assertSame($reply, $this->finish($prepared, $this->success()));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            ActivityStatus::Cancelled,
            ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->status
        );
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $receipt = ActivityCancellationAcknowledgement::recordLocalStopped(
            $prepared['activity_attempt_id'],
            'portable-worker',
            $run->cancellation_request_command_id,
            2,
        );
        $this->assertTrue($receipt['acknowledged']);
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function testAResponseLostBeforeLaterCancellationStillReturnsThePriorCommittedSuccess(): void
    {
        [$run, , $prepared] = $this->preparedClaim();
        $first = $this->finish($prepared, $this->success());
        $this->assertTrue($first['recorded']);
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->requestCancellation('maintenance', 30)->accepted()
        );
        $count = $run->historyEvents()
            ->count();
        $this->assertSame([
            ...$first,
            'duplicate' => true,
        ], $this->finish($prepared, $this->success()));
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
    }

    public function testMissingCanonicalPreparationCannotBeReplacedByMutableRunningRows(): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->delete();
        $before = $task->getAttributes();
        $this->assertSame('local_activity_preparation_mismatch', $this->finish($prepared, $this->success())['reason']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            ActivityStatus::Running,
            ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->status
        );
    }

    #[DataProvider('expiredTimeouts')]
    public function testAnExpiredActivityDeadlineWinsOverAnIncomingSuccessfulResult(string $field, string $kind): void
    {
        [$run, $task, $prepared] = $this->preparedClaim([
            $field => 1,
        ]);
        Carbon::setTestNow(now()->addSeconds(2));
        try {
            $before = $task->getAttributes();
            $reply = $this->finish($prepared, $this->success());
            $this->assertTrue($reply['recorded']);
            $this->assertSame('ActivityTimedOut', $reply['event_type']);
            $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
            $this->assertSame($kind, $event->payload['timeout_kind']);
            $this->assertSame('completed', $event->payload['local_outcome']['submitted_outcome']);
            $this->assertSame($before, $task->refresh()->getAttributes());
            $this->assertSame([
                ...$reply,
                'duplicate' => true,
            ], $this->finish($prepared, $this->success()));
            $this->assertSame(
                0,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCompleted)->count()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function expiredTimeouts(): iterable
    {
        yield 'start-to-close' => ['start_to_close_timeout', 'start_to_close'];
        yield 'schedule-to-close' => ['schedule_to_close_timeout', 'schedule_to_close'];
        yield 'heartbeat' => ['heartbeat_timeout', 'heartbeat'];
    }

    public function testAWorkerCannotInventAnEarlyTimeout(): void
    {
        [$run, , $prepared] = $this->preparedClaim([
            'start_to_close_timeout' => 10,
        ]);
        $this->assertSame('local_activity_timeout_not_due', $this->finish($prepared, [
            'outcome' => 'timed_out',
        ])['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testThePublishedProtocolDoesNotEnterTheCandidateOutcomePath(): void
    {
        [$run, , $prepared] = $this->preparedClaim();
        $reply = app(LocalActivityExecutor::class)->recordPortableOutcome(
            $prepared['activity_attempt_id'],
            'portable-worker',
            2,
            $this->success(),
        );
        $this->assertSame('local_activity_outcome_requires_protocol_1_20', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    #[DataProvider('invalidReports')]
    public function testMalformedOrUnpreparedReportsCannotChangeThePreparedRows(array $report): void
    {
        [$run, $task, $prepared] = $this->preparedClaim();
        $before = $task->getAttributes();
        $reply = $this->finish($prepared, $report);
        $this->assertFalse($reply['recorded']);
        $this->assertSame('invalid_local_activity_outcome', $reply['reason']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(2, $run->historyEvents()->count());
        $this->assertSame(
            ActivityStatus::Running,
            ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->status
        );
    }

    public static function invalidReports(): iterable
    {
        yield 'missing result' => [[
            'outcome' => 'completed',
            'payload_codec' => 'avro',
        ]];
        yield 'wrong codec' => [[
            'outcome' => 'completed',
            'result' => 'opaque',
            'payload_codec' => 'json',
        ]];
        yield 'attempt reconstruction' => [[
            'outcome' => 'completed',
            'attempts' => [],
        ]];
        yield 'cancellation is not a result report' => [[
            'outcome' => 'cancelled',
        ]];
        yield 'untyped retryability' => [[
            'outcome' => 'failed',
            'message' => 'failed',
            'non_retryable' => 'true',
        ]];
        yield 'invalid failure encoding' => [[
            'outcome' => 'failed',
            'message' => "invalid-\xFF",
        ]];
        yield 'failure cannot carry result bytes' => [[
            'outcome' => 'failed',
            'message' => 'failed',
            'result' => 'opaque',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function success(): array
    {
        return [
            'outcome' => 'completed',
            'result' => Serializer::serializeWithCodec('avro', [
                'value' => 'Hello, Taylor!',
            ]),
            'payload_codec' => 'avro',
        ];
    }

    /** @param array<string, mixed> $prepared
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function finish(array $prepared, array $report): array
    {
        return app(LocalActivityExecutor::class)->recordPortableOutcome(
            $prepared['activity_attempt_id'],
            'portable-worker',
            2,
            $report,
            '1.20'
        );
    }

    /** @param array<string, mixed> $options
     * @return array{WorkflowRun, WorkflowTask, array<string, mixed>}
     */
    private function preparedClaim(array $options = []): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 2,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'portable-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->refresh();
        $prepared = PortableLocalActivityPreparation::prepare($task->id, 'portable-worker', 2, 1, 'sdk-local-attempt', [
            'type' => 'record_local_activity',
            'activity_type' => 'python-local-opaque',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            ...$options,
        ], '1.20');
        $this->assertTrue($prepared['prepared']);

        return [$run->refresh(), $task, $prepared];
    }
}
