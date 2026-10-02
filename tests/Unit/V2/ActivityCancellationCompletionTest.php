<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellationCompletion;
use Workflow\V2\Support\ActivityCancellationWait;

final class ActivityCancellationCompletionTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[DataProvider('callbackModes')]
    public function testOnlyTheOriginalOwnersCanonicalStopReceiptResolvesTheWait(bool $local): void
    {
        $run = $this->fixtureRun($local);
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
        $receipt = $this->receipt($local);
        $run->historyEvents->push($receipt);
        $this->assertTrue(ActivityCancellationCompletion::resolved($run, 'activity'));
        $this->assertSame($receipt, ActivityCancellationCompletion::stopReceipt($run, $run->historyEvents[3]));
    }

    public static function callbackModes(): iterable
    {
        yield 'remote' => [false];
        yield 'local' => [true];
    }

    public function testAnOlderScheduleKeepsTryCancelAndAnExplicitCanonicalPolicySurvivesColdReplay(): void
    {
        $run = $this->fixtureRun();
        $this->assertSame(CancellationPolicy::TryCancel, ActivityCancellationWait::policy($run, 1));
        $scheduled = $run->historyEvents[0];
        $scheduled->payload = [
            ...$scheduled->payload,
            'activity' => [
                'cancellation_policy' => CancellationPolicy::WaitCancellationCompleted->value,
            ],
        ];
        $this->assertSame(CancellationPolicy::WaitCancellationCompleted, ActivityCancellationWait::policy($run, 1));
    }

    public function testTheCompletedHostingClaimPreservesBoundaryRootAndDeadlineAcrossReplacement(): void
    {
        $run = $this->fixtureRun();
        $task = new WorkflowTask([
            'status' => TaskStatus::Completed,
            'payload' => [
                'cancellation_activity_wait' => [
                    'request_id' => 'request',
                    'root_request_id' => 'request',
                    'cleanup_deadline_at' => '2026-10-02T13:00:30.000000Z',
                    'execution_ids' => ['activity'],
                ],
            ],
        ]);
        ActivityCancellationWait::bindBoundary($task, 1, 'activity', 1, null, 1);
        $run->setRelation('tasks', new Collection([$task]));
        $this->assertNull(ActivityCancellationWait::validateBoundary($run, 1, 'activity', 1, null, 1));
        $this->assertSame(
            'cancellation_wait_boundary_mismatch',
            ActivityCancellationWait::validateBoundary($run, 2, 'timer', 1, null, 1)
        );
        $payload = $task->payload;
        $payload['cancellation_activity_wait']['cleanup_deadline_at'] = '2026-10-02T13:01:30.000000Z';
        $task->payload = $payload;
        $this->assertSame(
            'cancellation_wait_context_mismatch',
            ActivityCancellationWait::validateBoundary($run, 1, 'activity', 1, null, 1)
        );
    }

    #[DataProvider('invalidReceiptFences')]
    public function testAChangedReceiptCannotResolveAnotherAttemptsWait(string $field, mixed $value): void
    {
        $run = $this->fixtureRun();
        $receipt = $this->receipt();
        $receipt->payload = [
            ...$receipt->payload,
            $field => $value,
        ];
        $run->historyEvents->push($receipt);
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public static function invalidReceiptFences(): iterable
    {
        foreach ([
            'sequence' => 2,
            'activity_execution_id' => 'other-activity',
            'activity_attempt_id' => 'replacement-attempt',
            'lease_owner' => 'replacement-worker',
            'cancellation_history_event_id' => 'other-fence',
            'request_id' => 'other-request',
            'root_request_id' => 'other-root',
            'cleanup_deadline_at' => '2026-10-02T13:00:31.000000Z',
            'callback_state' => 'unknown',
            'evidence_source' => 'workflow_worker',
        ] as $field => $value) {
            yield $field => [$field, $value];
            yield $field . ' missing' => [$field, null];
        }
    }

    public function testAnExpiredLeaseAndTerminalProjectionDoNotProveCallbackExit(): void
    {
        $run = $this->fixtureRun();
        $run->status = 'cancelled';
        $run->closed_at = '2026-10-02T13:00:30Z';
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public function testCancellationBeforeAdmissionNeedsBothCanonicalScheduleAndNoAttemptFence(): void
    {
        $run = $this->fixtureRun();
        $run->historyEvents->forget(1);
        $cancelled = $run->historyEvents[3];
        $cancelled->payload = [
            ...$cancelled->payload,
            'activity_attempt_id' => null,
            'activity_attempt' => null,
        ];
        $this->assertTrue(ActivityCancellationCompletion::resolved($run, 'activity'));
        $run->historyEvents->forget(0);
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public function testAnOmittedAttemptDoesNotImpersonateARecordedNoAttemptFence(): void
    {
        $run = $this->fixtureRun();
        $run->historyEvents->forget(1);
        $payload = $run->historyEvents[3]->payload;
        unset($payload['activity_attempt_id']);
        $payload['activity_attempt'] = null;
        $run->historyEvents[3]->payload = $payload;
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public function testAnEarlierReceiptOrDifferentCommandCannotResolveTheWait(): void
    {
        $run = $this->fixtureRun();
        $receipt = $this->receipt();
        $receipt->sequence = 3;
        $run->historyEvents->push($receipt);
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
        $receipt->sequence = 5;
        $receipt->workflow_command_id = 'other-request';
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public function testAPostFenceOutcomeCannotImpersonateCallbackStopAcknowledgement(): void
    {
        $run = $this->fixtureRun();
        $run->historyEvents->push($this->event(HistoryEventType::ActivityCompleted, 5, [
            'sequence' => 1,
            'activity_execution_id' => 'activity',
            'activity_attempt_id' => 'attempt',
        ]));
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    #[DataProvider('outcomes')]
    public function testOnlyAnOutcomeOfTheLatestStartedAttemptProvesCallbackCompletion(
        HistoryEventType $type,
        bool $resolved,
    ): void {
        $run = $this->fixtureRun();
        $run->historyEvents->forget(3);
        $run->historyEvents->push($this->event($type, 4, [
            'sequence' => 1,
            'activity_execution_id' => 'activity',
            'activity_attempt_id' => 'attempt',
        ]));
        $this->assertSame($resolved, ActivityCancellationCompletion::resolved($run, 'activity'));
        $run->historyEvents->push($this->event(HistoryEventType::ActivityStarted, 5, [
            'sequence' => 1,
            'activity_execution_id' => 'activity',
            'activity_attempt_id' => 'replacement-attempt',
        ]));
        $this->assertFalse(ActivityCancellationCompletion::resolved($run, 'activity'));
    }

    public static function outcomes(): iterable
    {
        yield 'completed callback' => [HistoryEventType::ActivityCompleted, true];
        yield 'failed callback' => [HistoryEventType::ActivityFailed, true];
        yield 'timeout fence' => [HistoryEventType::ActivityTimedOut, false];
        yield 'retry scheduling' => [HistoryEventType::ActivityRetryScheduled, false];
    }

    private function fixtureRun(bool $local = false): WorkflowRun
    {
        $run = new WorkflowRun([
            'id' => 'run',
            'cancellation_request_command_id' => 'request',
        ]);
        $run->setRelation('historyEvents', new Collection([
            $this->event(HistoryEventType::ActivityScheduled, 1, [
                'sequence' => 1,
                'activity_execution_id' => 'activity',
            ]),
            $this->event(HistoryEventType::ActivityStarted, 2, [
                'sequence' => 1,
                'activity_execution_id' => 'activity',
                'activity_attempt_id' => 'attempt',
            ]),
            $this->event(HistoryEventType::CooperativeCancellationRequested, 3, [
                'cancellation' => [
                    'schema' => 'durable-workflow.cancellation-context/v1',
                    'request_id' => 'request',
                    'root_request_id' => 'request',
                    'root_workflow_instance_id' => 'instance',
                    'root_workflow_run_id' => 'run',
                    'parent_request_id' => null,
                    'reason' => 'shutdown',
                    'requester' => [
                        'type' => 'operator',
                    ],
                    'source' => 'control_plane',
                    'requested_at' => '2026-10-02T13:00:00.000000Z',
                    'cleanup_deadline_at' => '2026-10-02T13:00:30.000000Z',
                    'lineage' => [[
                        'request_id' => 'request',
                        'workflow_instance_id' => 'instance',
                        'workflow_run_id' => 'run',
                    ]],
                ],
            ]),
            $this->event(HistoryEventType::ActivityCancelled, 4, [
                'sequence' => 1,
                'activity_execution_id' => 'activity',
                'activity_attempt_id' => 'attempt',
                'local_activity' => $local,
                'activity_attempt' => [
                    'id' => 'attempt',
                    'activity_execution_id' => 'activity',
                    'status' => 'cancelled',
                    'lease_owner' => 'original-worker',
                ],
            ]),
        ]));
        return $run;
    }

    private function receipt(bool $local = false): WorkflowHistoryEvent
    {
        return $this->event(HistoryEventType::ActivityCancellationAcknowledged, 5, [
            'sequence' => 1,
            'activity_execution_id' => 'activity',
            'activity_attempt_id' => 'attempt',
            'lease_owner' => 'original-worker',
            'cancellation_history_event_id' => 'event-4',
            'request_id' => 'request',
            'root_request_id' => 'request',
            'cleanup_deadline_at' => '2026-10-02T13:00:30.000000Z',
            'callback_state' => 'stopped',
            'evidence_source' => $local ? 'workflow_worker' : 'activity_worker',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(HistoryEventType $type, int $sequence, array $payload): WorkflowHistoryEvent
    {
        return new WorkflowHistoryEvent([
            'id' => 'event-' . $sequence,
            'workflow_run_id' => 'run',
            'workflow_command_id' => 'request',
            'event_type' => $type,
            'sequence' => $sequence,
            'payload' => $payload,
        ]);
    }
}
