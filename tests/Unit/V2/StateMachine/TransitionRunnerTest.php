<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\V2\TestBusinessStateMachineWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;

final class TransitionRunnerTest extends TestCase
{
    public function testFourStepsRunAsRealRecordedActivitiesWithFreshEvidence(): void
    {
        $runner = $this->runner();
        $before = $runner->step();
        $this->assertSame('example.snapshot', $before->activity->activity);
        $admit = $runner->step($this->snapshot($before));
        $this->assertSame('example.admit', $admit->activity->activity);
        $first = $runner->step($this->admission($admit));
        $this->assertSame('example.first-effect', $first->activity->activity);
        $second = $runner->step('first-created');
        $this->assertSame('example.second-effect', $second->activity->activity);
        $after = $runner->step('second-created');
        $this->assertSame('after', $after->activity->arguments[0]['phase']);
        $commit = $runner->step($this->snapshot($after, 'satisfied'));
        $this->assertSame('example.commit', $commit->activity->activity);
        $this->assertSame('snapshot-after', $commit->activity->arguments[0]['snapshotId']);
        $done = $runner->step($this->commit($commit));
        $this->assertTrue($done->completed);
        $this->assertSame('committed', $done->result['status']);
    }

    public function testSecondIntegrationSkipsAllActionsWhenEvidenceAlreadyExists(): void
    {
        $runner = $this->runner([
            'profile' => 'imported',
        ]);
        $before = $runner->step();
        $commit = $runner->step($this->snapshot($before, 'satisfied', 'unsatisfied'));
        $this->assertSame('example.commit', $commit->activity->activity);
        $this->assertSame('committed', $runner->step($this->commit($commit))->result['status']);
    }

    public function testSecondIntegrationSelectsItsOwnActionForTheSameStates(): void
    {
        $runner = $this->runner([
            'profile' => 'imported',
        ]);
        $admit = $runner->step($this->snapshot($runner->step()));
        $action = $runner->step($this->admission($admit));
        $this->assertSame('example.import-effect', $action->activity->activity);
        $after = $runner->step('imported');
        $this->assertSame('example.snapshot', $after->activity->activity);
        $this->assertSame('after', $after->activity->arguments[0]['phase']);
        $commit = $runner->step($this->snapshot($after, 'satisfied'));
        $this->assertSame('committed', $runner->step($this->commit($commit))->result['status']);
    }

    public function testSecondIntegrationCompensatesItsOriginalHandlerWithoutReceipt(): void
    {
        $runner = $this->runner([
            'profile' => 'imported',
            'compensateOnFailure' => true,
        ]);
        $admit = $runner->step($this->snapshot($runner->step()));
        $action = $runner->step($this->admission($admit));
        $snapshot = $runner->step('reject');
        $undo = $runner->step($this->snapshot($snapshot));
        $this->assertSame('example.reconcile-import', $undo->activity->activity);
        $this->assertSame($action->activity->arguments[0], $undo->activity->arguments[0]);
        $this->assertSame('compensated', $runner->step('verified')->result['status']);
    }

    public static function decisions(): array
    {
        return [
            'missing evidence and refused action' => ['unsatisfied', 'unsatisfied', 'blocked'],
            'unknown evidence' => ['unknown', 'satisfied', 'unknown'],
            'unknown permission' => ['unsatisfied', 'unknown', 'unknown'],
        ];
    }

    #[DataProvider('decisions')]
    public function testFalseAndUnknownAreNotPermissionToExecute(
        string $target,
        string $permission,
        string $expected
    ): void {
        $runner = $this->runner();
        $done = $runner->step($this->snapshot($runner->step(), $target, $permission));
        $this->assertTrue($done->completed);
        $this->assertSame($expected, $done->result['status']);
    }

    public static function sourceDecisions(): array
    {
        return [
            'no edge' => [[
                'event' => 'unrelated',
            ], 'unavailable'],
            'two edges' => [[
                'ambiguous' => true,
            ], 'ambiguous'],
        ];
    }

    #[DataProvider('sourceDecisions')]
    public function testStructuralSelectionDoesNotPerformIO(array $options, string $expected): void
    {
        $done = $this->runner($options)
            ->step();
        $this->assertTrue($done->completed);
        $this->assertSame($expected, $done->result['status']);
    }

    public function testMissingWorkWaitsWithoutRegistrationOrEffects(): void
    {
        $runner = $this->runner([
            'hasWork' => false,
        ]);
        $done = $runner->step($this->snapshot($runner->step()));
        $this->assertSame('pending', $done->result['status']);
    }

    public function testLiveAdmissionCanRefuseWorkAfterThePureGuardPassed(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $done = $runner->step($this->admission($admit, 'unsatisfied'));
        $this->assertSame('blocked', $done->result['status']);
        $this->assertSame('admission_unsatisfied', $done->result['reason']);
    }

    public function testPartialEffectCompensatesWithoutReceiptBeforeEarlierParentWork(): void
    {
        $runner = $this->runner([
            'compensateOnFailure' => true,
            'earlier' => true,
        ]);
        $admit = $runner->step($this->snapshot($runner->step()));
        $first = $runner->step($this->admission($admit));
        $runner->step('first-created');
        $compensation = $runner->step('reject');
        $this->assertSame('compensation', $compensation->activity->arguments[0]['phase']);
        $undo = $runner->step($this->snapshot($compensation));
        $this->assertSame('example.reconcile-and-compensate', $undo->activity->activity);
        $this->assertSame($first->activity->arguments[0], $undo->activity->arguments[0]);
        $earlier = $runner->step('verified');
        $this->assertSame('example.undo-earlier', $earlier->activity->activity);
        $this->assertSame([
            'status' => 'compensated',
        ], $runner->step('verified')  ->result);
    }

    public function testUnknownCompensationStopsSagaBeforeEarlierParentWork(): void
    {
        $runner = $this->runner([
            'compensateOnFailure' => true,
            'earlier' => true,
        ]);
        $admit = $runner->step($this->snapshot($runner->step()));
        $runner->step($this->admission($admit));
        $compensation = $runner->step('reject');
        $runner->step($this->snapshot($compensation));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compensation remains unresolved.');
        $runner->step('unknown');
    }

    public function testFreshButStillUnsatisfiedEvidenceCannotCommit(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $runner->step($this->admission($admit));
        $runner->step('first-created');
        $after = $runner->step('second-created');
        $done = $runner->step($this->snapshot($after));
        $this->assertSame('pending', $done->result['status']);
    }

    public static function substitutedSnapshots(): array
    {
        return [
            'scope substitution' => ['execution.scope', 'another-app'],
            'decision substitution' => ['execution.executionId', 'another-operation'],
            'phase substitution' => ['phase', 'after'],
            'transition substitution' => ['transitionKey', 'another-transition'],
        ];
    }

    #[DataProvider('substitutedSnapshots')]
    public function testSnapshotSubstitutionFailsBeforeEffects(string $field, string $value): void
    {
        $runner = $this->runner();
        $snapshot = $this->snapshot($runner->step());
        $this->replace($snapshot, $field, $value);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('snapshot does not match');
        $runner->step($snapshot);
    }

    public function testReusedSnapshotIdAfterWorkIsRejected(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $runner->step($this->admission($admit));
        $runner->step('first-created');
        $after = $runner->step('second-created');
        $snapshot = $this->snapshot($after, 'satisfied');
        $snapshot['snapshotId'] = 'snapshot-before';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('fresh recorded snapshot');
        $runner->step($snapshot);
    }

    public function testChangedSourceRevisionPreventsEvenAnAlreadySatisfiedCommit(): void
    {
        $runner = $this->runner();
        $snapshot = $this->snapshot($runner->step(), 'satisfied');
        $snapshot['revision'] = 'revision-2';
        $this->assertSame('conflict', $runner->step($snapshot)->result['status']);
    }

    public function testCancellationAtCommitDoesNotBecomeSuccess(): void
    {
        $runner = $this->runner();
        $commit = $runner->step($this->snapshot($runner->step(), 'satisfied'));
        $result = $this->commit($commit);
        $result['status'] = 'cancellation_requested';
        $result['reason'] = 'cancellation_won';
        $this->assertSame('cancellation_requested', $runner->step($result)->result['status']);
    }

    public function testSubstitutedCommitAcknowledgementFails(): void
    {
        $runner = $this->runner();
        $commit = $runner->step($this->snapshot($runner->step(), 'satisfied'));
        $result = $this->commit($commit);
        $result['execution']['scope'] = 'another-app';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('commit acknowledgement belongs');
        $runner->step($result);
    }

    public static function unavailablePolicies(): array
    {
        return [
            'changed definition' => [[
                'fingerprint' => 'old-fingerprint',
            ], 'pinned policy'],
            'removed handler' => [[
                'missingHandler' => true,
            ], 'handler is not registered'],
        ];
    }

    #[DataProvider('unavailablePolicies')]
    public function testUnavailablePinnedCodeFailsBeforeIO(array $options, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        $this->runner($options)
            ->step();
    }

    public function testCallingOutsideWorkflowCannotAccidentallyPerformIO(): void
    {
        $profile = TestBusinessStateMachineWorkflow::profile();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a V2 workflow');
        (new TransitionRunner())->run(
            new TestBusinessStateMachineWorkflow(),
            $profile,
            new ExecutionReference('scope', 'subject', 'execution', $profile->fingerprint(), 'Received', '1'),
            'received',
            new ActivityTransitionRuntime('snapshot', 'admit', 'commit'),
            []
        );
    }

    public function testColdReplayReusesEveryRecordedBoundaryWithoutSchedulingActions(): void
    {
        $runner = $this->runner();
        $before = $runner->step();
        $beforeResult = $this->snapshot($before);
        $admit = $runner->step($beforeResult);
        $admission = $this->admission($admit);
        $runner->step($admission);
        $runner->step('first-created');
        $after = $runner->step('second-created');
        $afterResult = $this->snapshot($after, 'satisfied');
        $commit = $runner->step($afterResult);
        $committed = $this->commit($commit);
        $expected = $runner->step($committed)
->result;
        $history = $this->history([
            ['example.snapshot', $beforeResult], ['example.admit', $admission],
            ['example.first-effect', 'first-created'], ['example.second-effect', 'second-created'],
            ['example.snapshot', $afterResult], ['example.commit', $committed],
        ]);
        $replayed = $this->runner()
            ->withHistoryEvents($history)
            ->step();
        $this->assertTrue($replayed->completed);
        $this->assertSame($expected, $replayed->result);
        $this->assertSame(['complete_workflow'], array_column($replayed->commands, 'type'));
    }

    public function testColdReplayRestoresSagaBeforeARecordedActivityFailureWithoutReceipt(): void
    {
        $options = [
            'compensateOnFailure' => true,
            'earlier' => true,
        ];
        $runner = $this->runner($options);
        $before = $this->snapshot($runner->step());
        $admission = $this->admission($runner->step($before));
        $history = $this->history([
            ['example.snapshot', $before], ['example.admit', $admission],
            ['example.first-effect', 'first-created'],
        ]);
        $history[] = [
            'sequence' => 5,
            'event_type' => 'ActivityFailed',
            'payload' => [
                'sequence' => 4,
                'activity_type' => 'example.second-effect',
                'exception_class' => RuntimeException::class,
                'exception_type' => 'ProviderFailure',
                'message' => 'Result unavailable',
                'code' => 0,
            ],
            'recorded_at' => '2026-11-09T10:00:00+00:00',
        ];
        $cold = $this->runner($options)
            ->withHistoryEvents($history);
        $snapshot = $cold->step();
        $this->assertSame('compensation', $snapshot->activity->arguments[0]['phase']);
        $undo = $cold->step($this->snapshot($snapshot));
        $this->assertSame('example.reconcile-and-compensate', $undo->activity->activity);
        $this->assertSame($before['execution'], $undo->activity->arguments[0]);
        $this->assertSame('example.undo-earlier', $cold->step('verified')->activity->activity);
        $this->assertSame([
            'status' => 'compensated',
        ], $cold->step('verified')  ->result);
    }

    public function testSubstitutedAdmissionFailsBeforeWork(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $response = $this->admission($admit);
        $response['execution']['scope'] = 'another-app';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('admission belongs');
        $runner->step($response);
    }

    public function testChangedSourceAfterWorkDoesNotCommitOverAnotherTransition(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $runner->step($this->admission($admit));
        $runner->step('first-created');
        $after = $this->snapshot($runner->step('second-created'), 'satisfied');
        $after['state'] = 'Processing';
        $after['revision'] = 'revision-2';
        $this->assertSame('conflict', $runner->step($after)->result['status']);
    }

    private function history(array $activities): array
    {
        $history = [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-11-09T10:00:00+00:00',
        ]];
        foreach ($activities as $index => [$activity, $result]) {
            $history[] = [
                'sequence' => $index + 2,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => $index + 1,
                    'activity_type' => $activity,
                    'result' => Serializer::serializeWithCodec('avro', $result),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-11-09T10:00:00+00:00',
            ];
        }
        return $history;
    }

    private function runner(array $options = []): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass(
            TestBusinessStateMachineWorkflow::class,
            'workflow-1',
            'run-1',
            [$options]
        );
    }

    private function snapshot(
        WorkflowStep $step,
        string $target = 'unsatisfied',
        string $permission = 'satisfied'
    ): array {
        $request = $step->activity->arguments[0];
        return [
            'execution' => $request['execution'],
            'transitionKey' => $request['transition']['key'],
            'phase' => $request['phase'],
            'snapshotId' => 'snapshot-' . $request['phase'],
            'state' => 'Received',
            'revision' => 'revision-1',
            'target' => [
                'verdict' => $target,
                'reason' => 'target_' . $target,
                'evidence' => ['evidence-1'],
            ],
            'permission' => [
                'verdict' => $permission,
                'reason' => 'permission_' . $permission,
                'evidence' => [],
            ],
        ];
    }

    private function admission(WorkflowStep $step, string $verdict = 'satisfied'): array
    {
        return [
            'execution' => $step->activity->arguments[0]['execution'],
            'decision' => [
                'verdict' => $verdict,
                'reason' => 'admission_' . $verdict,
                'evidence' => ['permit-1'],
            ],
        ];
    }

    private function commit(WorkflowStep $step): array
    {
        return [
            'execution' => $step->activity->arguments[0]['execution'],
            'status' => 'committed',
            'reason' => 'transition_committed',
        ];
    }

    private function replace(array &$payload, string $field, string $value): void
    {
        $parts = explode('.', $field);
        if (count($parts) === 2) {
            $payload[$parts[0]][$parts[1]] = $value;
        } else {
            $payload[$field] = $value;
        }
    }
}
