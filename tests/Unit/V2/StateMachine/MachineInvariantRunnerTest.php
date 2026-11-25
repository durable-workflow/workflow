<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestDefinedMachineWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;

final class MachineInvariantRunnerTest extends TestCase
{
    public static function refusals(): array
    {
        return [['prepare', 'unsatisfied', 'blocked'], ['imported', 'unsatisfied', 'blocked'],
            ['prepare', 'unknown', 'unknown'], ['imported', 'unknown', 'unknown']];
    }

    #[DataProvider('refusals')]
    public function testProfilesCannotCommitAnAlreadySatisfiedTargetPastTheirSharedInvariant(
        string $profile,
        string $invariant,
        string $expected,
    ): void {
        $runner = $this->runner([
            'profile' => $profile,
        ]);
        $done = $runner->step($this->snapshot($runner->step(), 'satisfied', $invariant));
        $this->assertTrue($done->completed);
        $this->assertSame($expected, $done->result['status']);
        $this->assertSame('owner_' . $invariant, $done->result['reason']);
        $this->assertSame('invariant', $done->result['explanation']['stage']);
        $this->assertSame('before', $done->result['explanation']['snapshot']['phase']);
        $this->assertSame(['complete_workflow'], array_column($done->commands, 'type'));
    }

    public function testAnInvariantRevokedAfterWorkPreventsCommitWithoutInventingCompensationSuccess(): void
    {
        $runner = $this->runner();
        $admit = $runner->step($this->snapshot($runner->step()));
        $first = $runner->step($this->admission($admit));
        $this->assertSame('example.first-effect', $first->activity->activity);
        $runner->step('first-created');
        $after = $runner->step('second-created');
        $done = $runner->step($this->snapshot($after, 'satisfied', 'unsatisfied'));
        $this->assertSame('blocked', $done->result['status']);
        // The revocation is attributed to the post-work snapshot, not the admission that preceded it.
        $this->assertSame('invariant', $done->result['explanation']['stage']);
        $this->assertSame('after', $done->result['explanation']['snapshot']['phase']);
        $this->assertSame(['complete_workflow'], array_column($done->commands, 'type'));
    }

    public function testAProfileConditionNamedForTheInvariantCannotReplaceTheSharedGate(): void
    {
        $runner = $this->runner([
            'profile' => 'imported',
            'overrideInvariant' => true,
        ]);
        $done = $runner->step($this->snapshot($runner->step(), 'satisfied', 'unsatisfied'));
        $this->assertSame('blocked', $done->result['status']);
        $this->assertSame('owner_unsatisfied', $done->result['reason']);
        $this->assertSame(['complete_workflow'], array_column($done->commands, 'type'));
    }

    public function testCompensationStillReconcilesOriginalWorkWhenTheForwardInvariantIsNoLongerSatisfied(): void
    {
        $runner = $this->runner([
            'compensateOnFailure' => true,
        ]);
        $admit = $runner->step($this->snapshot($runner->step()));
        $first = $runner->step($this->admission($admit));
        $compensationSnapshot = $runner->step('reject');
        $this->assertSame('compensation', $compensationSnapshot->activity->arguments[0]['phase']);
        $undo = $runner->step($this->snapshot($compensationSnapshot, 'unsatisfied', 'unsatisfied'));
        $this->assertSame('example.reconcile-and-compensate', $undo->activity->activity);
        $this->assertSame($first->activity->arguments[0], $undo->activity->arguments[0]);
        $this->assertSame('compensated', $runner->step('verified')->result['status']);
    }

    public function testOmittedInvariantAssessmentFailsBeforeAdmissionOrEffects(): void
    {
        $runner = $this->runner();
        $snapshot = $this->snapshot($runner->step());
        unset($snapshot['machineAssessment']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('omitted its required machine invariant');
        $runner->step($snapshot);
    }

    public static function substitutions(): array
    {
        return [['definitionFingerprint'], ['invariantFingerprint'], ['snapshotId']];
    }

    #[DataProvider('substitutions')]
    public function testAProofFromAnotherDefinitionConditionOrSnapshotCannotAuthorizeWork(string $field): void
    {
        $runner = $this->runner();
        $snapshot = $this->snapshot($runner->step());
        $snapshot['machineAssessment'][$field] = $field === 'snapshotId' ? 'different-snapshot' : str_repeat('f', 64);
        $this->expectException(InvalidArgumentException::class);
        $runner->step($snapshot);
    }

    public function testAProfileCannotSilentlyUseTheLegacyRuntimeWithoutDefinitionBinding(): void
    {
        $runner = $this->runner([
            'omitRuntimeDefinition' => true,
        ]);
        $snapshot = $this->snapshot($runner->step());
        unset($snapshot['machineAssessment']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exact pinned machine invariant');
        $runner->step($snapshot);
    }

    public function testTheLegacyRuntimeRefusesAnUnselectedInvariantChannel(): void
    {
        $runner = $this->runner([
            'omitRuntimeDefinition' => true,
        ]);
        $snapshot = $this->snapshot($runner->step());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unselected machine invariant');
        $runner->step($snapshot);
    }

    public function testColdReplayReusesRecordedInvariantProofWithoutAnotherAssessmentOrEffect(): void
    {
        $runner = $this->runner();
        $before = $this->snapshot($runner->step());
        $admit = $runner->step($before);
        $admission = $this->admission($admit);
        $runner->step($admission);
        $runner->step('first-created');
        $after = $this->snapshot($runner->step('second-created'), 'satisfied');
        $commit = $runner->step($after);
        $this->assertSame($after['machineAssessment'], $commit->activity->arguments[0]['machineAssessment']);
        $committed = [
            'execution' => $commit->activity->arguments[0]['execution'],
            'status' => 'committed',
            'reason' => 'business_state_committed',
        ];
        $expected = $runner->step($committed)
->result;
        $events = [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
        ]];
        foreach ([['defined.snapshot', $before],
            ['defined.admit', $admission],
            ['example.first-effect', 'first-created'],
            ['example.second-effect', 'second-created'],
            ['defined.snapshot', $after],
            ['defined.commit', $committed]] as $i => [$type,$result]) {
            $events[] = [
                'sequence' => $i + 2,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => $i + 1,
                    'activity_type' => $type,
                    'result' => Serializer::serializeWithCodec('avro', $result),
                    'payload_codec' => 'avro',
                ],
            ];
        }
        $done = $this->runner()
            ->withHistoryEvents($events)
            ->step();
        $this->assertSame($expected, $done->result);
        $this->assertSame(['complete_workflow'], array_column($done->commands, 'type'));
    }

    private function runner(array $options = []): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass(TestDefinedMachineWorkflow::class, 'workflow-1', 'run-1', [$options]);
    }

    private function snapshot(
        WorkflowStep $step,
        string $target = 'unsatisfied',
        string $invariant = 'satisfied'
    ): array {
        $request = $step->activity->arguments[0];
        $id = 'snapshot-' . $request['phase'];
        return [
            'execution' => $request['execution'],
            'transitionKey' => $request['transition']['key'],
            'phase' => $request['phase'],
            'snapshotId' => $id,
            'state' => 'Received',
            'revision' => 'revision-1',
            'target' => [
                'verdict' => $target,
                'reason' => 'target_' . $target,
                'evidence' => ['target-proof'],
            ],
            'permission' => [
                'verdict' => 'satisfied',
                'reason' => 'permitted',
                'evidence' => ['permission-proof'],
            ],
            'machineAssessment' => TestDefinedMachineWorkflow::assessment($id, $invariant)->toArray(),
        ];
    }

    private function admission(WorkflowStep $step): array
    {
        return [
            'execution' => $step->activity->arguments[0]['execution'],
            'decision' => [
                'verdict' => 'satisfied',
                'reason' => 'admitted',
                'evidence' => ['admission-proof'],
            ],
        ];
    }
}
