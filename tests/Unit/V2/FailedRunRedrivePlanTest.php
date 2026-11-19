<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\FailedRunRedrivePlan;

final class FailedRunRedrivePlanTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $application->instance('config', new Repository([]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function testAcceptsCompletedLinearPrefixAndRecordedFailedActivity(): void
    {
        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($this->linearFailureHistory()));

        self::assertTrue($plan['eligible']);
        self::assertSame(2, $plan['resume_step_sequence']);
        self::assertCount(1, $plan['completed']);
        self::assertSame(1, $plan['completed'][0]['sequence']);
    }

    public function testRefusesFailureWithoutAnAuthoritativeBoundary(): void
    {
        $history = $this->linearFailureHistory();
        unset($history[5]['payload']['failed_step_sequence']);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unrecorded_failure_boundary', $plan['reason']);
    }

    public function testRefusesActivityProgressAfterTheClaimedFailedStep(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 5, 0, [[
            'event_type' => HistoryEventType::ActivityHeartbeatRecorded,
            'payload' => [
                'sequence' => 2,
                'activity_type' => 'second',
            ],
        ]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unrecorded_failure_boundary', $plan['reason']);
    }

    public function testRefusesSideEffectThatSharesAnActivityStep(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 3, 0, [[
            'event_type' => HistoryEventType::SideEffectRecorded,
            'payload' => [
                'sequence' => 1,
            ],
        ]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('duplicate_step_sequence', $plan['reason']);
    }

    public function testRefusesUnsupportedHistoryInCompletedPrefix(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 3, 0, [[
            'event_type' => HistoryEventType::ConditionWaitOpened,
            'payload' => [
                // Distinct from the activity steps (1 and 2) so the refusal
                // can only come from the unsupported event type.
                'sequence' => 3,
            ],
        ]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_history', $plan['reason']);
    }

    public function testRefusesAChildCallThatNeverClosed(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 3, 0, [[
            'event_type' => HistoryEventType::ChildWorkflowScheduled,
            'payload' => [
                'sequence' => 3,
                'workflow_link_id' => 'link-1',
                'child_workflow_instance_id' => 'child-1',
                'child_workflow_run_id' => 'child-run-1',
                'child_workflow_type' => 'child',
            ],
        ]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_open_child', $plan['reason']);
    }

    public function testRefusesCompletedLocalActivityThatWouldRunAgain(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['execution_mode'] = 'local';
        $history[3]['payload']['local_activity'] = true;

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_local_activity', $plan['reason']);
    }

    public function testRefusesMissingOrCorruptCompletedResult(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['result'] = 'invalid-avro';

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unavailable_activity_result', $plan['reason']);
    }

    public function testRefusesDuplicateCompletedActivity(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 4, 0, [$history[3]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('duplicate_activity_completion', $plan['reason']);
    }

    public function testRefusesExternalCompletedResultWithoutIndependentRetention(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['result'] = [
            'codec' => 'avro',
            'external_storage' => [
                'key' => 'source-run-only',
            ],
        ];

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('external_activity_result_not_supported', $plan['reason']);
    }

    public function testRefusesActivitySequenceGap(): void
    {
        $history = $this->linearFailureHistory();
        $history[4]['payload']['sequence'] = 3;

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('activity_sequence_gap', $plan['reason']);
    }

    public function testRefusesNonFailedRun(): void
    {
        $run = $this->runWithHistory($this->linearFailureHistory(), RunStatus::Completed);

        $plan = FailedRunRedrivePlan::forRun($run);

        self::assertFalse($plan['eligible']);
        self::assertSame('run_not_failed', $plan['reason']);
    }

    public function testNativeRetriesCanPrecedeTheRecordedFailedActivity(): void
    {
        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($this->retryHistory()));
        self::assertTrue($plan['eligible'], (string) $plan['reason']);
        self::assertSame(2, $plan['resume_step_sequence']);
    }

    public function testARetriedCompletedPrefixIsReusable(): void
    {
        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($this->completedRetryHistory()));

        self::assertTrue($plan['eligible'], (string) $plan['reason']);
        self::assertSame(2, $plan['resume_step_sequence']);
        self::assertCount(1, $plan['completed']);
        self::assertSame(1, $plan['completed'][0]['sequence']);
    }

    public function testHeartbeatsBetweenRetriedPrefixAttemptsDoNotPreventReuse(): void
    {
        $history = $this->completedRetryHistory();
        foreach ($history as $index => $event) {
            if ($event['event_type'] === HistoryEventType::ActivityStarted
                && ($event['payload']['sequence'] ?? null) === 1) {
                array_splice($history, $index + 1, 0, [[
                    'event_type' => HistoryEventType::ActivityHeartbeatRecorded,
                    'payload' => $event['payload'],
                ]]);
                break;
            }
        }
        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertTrue($plan['eligible'], (string) $plan['reason']);
        self::assertSame(2, $plan['resume_step_sequence']);
        self::assertCount(1, $plan['completed']);
    }

    public function testARejectedStartDoesNotBecomeActiveAdoptionEvidence(): void
    {
        $run = $this->adoptionRun();
        $run->historyEvents[2]->event_type = HistoryEventType::StartRejected;
        $plan = FailedRunRedrivePlan::forRun($run);

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_history', $plan['reason']);
    }

    public function testRetryIdentitySubstitutionIsRefused(): void
    {
        foreach (['activity_execution_id', 'activity_attempt_id', 'retry_after_attempt', 'retry_task_id'] as $field) {
            $history = $this->retryHistory();
            $history[6]['payload'][$field] = $field === 'retry_after_attempt' ? 9 : 'substituted';
            $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));
            self::assertFalse($plan['eligible']);
            self::assertSame('invalid_activity_retry_history', $plan['reason']);
        }
    }

    public function testNativeActiveAdoptionIsHarmlessOnlyWhenBoundToTheSameRunAndCommand(): void
    {
        $plan = FailedRunRedrivePlan::forRun($this->adoptionRun());
        self::assertTrue($plan['eligible'], (string) $plan['reason']);
        self::assertSame(2, $plan['resume_step_sequence']);
    }

    public function testSubstitutedActiveStartAdoptionCannotAuthorizeRedrive(): void
    {
        foreach ([
            ['outcome', 'started_new'],
            ['workflow_instance_id', 'other-instance'],
            ['workflow_run_id', 'other-run'],
            ['workflow_class', 'OtherWorkflow'],
            ['workflow_type', 'other.type'],
            ['workflow_command_id', 'other-command'],
            ['rejection_reason', 'refused'],
            ['command', null],
            ['command.id', 'other-command'],
            ['command.type', 'signal'],
            ['command.status', 'rejected'],
            ['command.outcome', 'started_new'],
            ['command.target_scope', 'run'],
            ['command.resolved_run_id', 'other-run'],
        ] as [$field, $replacement]) {
            $run = $this->adoptionRun();
            $event = $run->historyEvents[2];
            $payload = $event->payload;
            $keys = explode('.', $field);
            if (count($keys) === 2) {
                $payload[$keys[0]][$keys[1]] = $replacement;
            } else {
                $payload[$field] = $replacement;
            }
            $event->payload = $payload;
            $plan = FailedRunRedrivePlan::forRun($run);
            self::assertFalse($plan['eligible'], $field);
            self::assertSame('unexpected_start', $plan['reason'], $field);
        }
        foreach (['workflow_run_id', 'workflow_command_id'] as $field) {
            $run = $this->adoptionRun();
            $run->historyEvents[2]->setAttribute($field, 'substituted');
            self::assertSame('unexpected_start', FailedRunRedrivePlan::forRun($run)['reason']);
        }
    }

    private function adoptionRun(): WorkflowRun
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 2, 0, [[
            'event_type' => HistoryEventType::StartAccepted,
            'payload' => [
                'workflow_instance_id' => 'instance-a',
                'workflow_run_id' => 'run-a',
                'workflow_class' => 'TestWorkflow',
                'workflow_type' => 'test.workflow',
                'workflow_command_id' => 'adoption-command',
                'outcome' => 'returned_existing_active',
                'command' => [
                    'id' => 'adoption-command',
                    'type' => 'start',
                    'target_scope' => 'instance',
                    'resolved_run_id' => 'run-a',
                    'status' => 'accepted',
                    'outcome' => 'returned_existing_active',
                ],
            ],
        ]]);
        $run = $this->runWithHistory($history);
        $run->forceFill([
            'id' => 'run-a',
            'workflow_instance_id' => 'instance-a',
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test.workflow',
        ]);
        $run->historyEvents[2]->forceFill([
            'workflow_run_id' => 'run-a',
            'workflow_command_id' => 'adoption-command',
        ]);
        return $run;
    }

    private function retryHistory(): array
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 4, 1, [
            [
                'event_type' => HistoryEventType::ActivityScheduled,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                    'activity_execution_id' => 'execution-2',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityStarted,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                    'activity_execution_id' => 'execution-2',
                    'activity_attempt_id' => 'attempt-1',
                    'attempt_number' => 1,
                    'workflow_task_id' => 'task-1',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityRetryScheduled,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                    'activity_execution_id' => 'execution-2',
                    'activity_attempt_id' => 'attempt-1',
                    'retry_after_attempt_id' => 'attempt-1',
                    'retry_after_attempt' => 1,
                    'retry_of_task_id' => 'task-1',
                    'retry_task_id' => 'task-2',
                    'max_attempts' => 2,
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityStarted,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                    'activity_execution_id' => 'execution-2',
                    'activity_attempt_id' => 'attempt-2',
                    'attempt_number' => 2,
                    'workflow_task_id' => 'task-2',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityFailed,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                    'activity_execution_id' => 'execution-2',
                    'activity_attempt_id' => 'attempt-2',
                    'attempt_number' => 2,
                ],
            ],
        ]);
        return $history;
    }

    /**
     * @return list<array{event_type: HistoryEventType, payload: array<string, mixed>}>
     */
    private function completedRetryHistory(): array
    {
        $fixture = json_decode(
            file_get_contents(
                dirname(__DIR__, 2) . '/Fixtures/V2/ReplayRegression/redrive-retried-completed-activity.json'
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return array_map(static function (array $event): array {
            $event['event_type'] = HistoryEventType::from($event['event_type']);
            return $event;
        }, $fixture['redrive_plan']['source_history']);
    }

    /**
     * @return list<array{event_type: HistoryEventType, payload: array<string, mixed>}>
     */
    private function linearFailureHistory(): array
    {
        return [
            [
                'event_type' => HistoryEventType::StartAccepted,
                'payload' => [],
            ],
            [
                'event_type' => HistoryEventType::WorkflowStarted,
                'payload' => [],
            ],
            [
                'event_type' => HistoryEventType::ActivityScheduled,
                'payload' => [
                    'sequence' => 1,
                    'activity_type' => 'first',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityCompleted,
                'payload' => [
                    'sequence' => 1,
                    'activity_type' => 'first',
                    'payload_codec' => 'avro',
                    'result' => Serializer::serializeWithCodec('avro', 'done'),
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityFailed,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                ],
            ],
            [
                'event_type' => HistoryEventType::WorkflowFailed,
                'payload' => [
                    'failed_step_sequence' => 2,
                    'failed_step_kind' => 'activity',
                ],
            ],
        ];
    }

    /**
     * @param list<array{event_type: HistoryEventType, payload: array<string, mixed>}> $history
     */
    private function runWithHistory(array $history, RunStatus $status = RunStatus::Failed): WorkflowRun
    {
        $run = new WorkflowRun([
            'status' => $status,
            'payload_codec' => 'avro',
        ]);
        $run->setRelation('historyEvents', new Collection(array_map(
            static fn (array $entry, int $index): WorkflowHistoryEvent => new WorkflowHistoryEvent([
                'sequence' => $index + 1,
                'event_type' => $entry['event_type'],
                'payload' => $entry['payload'],
            ]),
            $history,
            array_keys($history),
        )));

        return $run;
    }
}
