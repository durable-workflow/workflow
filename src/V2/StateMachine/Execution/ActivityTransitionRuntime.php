<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use LogicException;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Persistence\CommitResult;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

/** Records application boundary results with ordinary DW activities and portable payloads. */
final readonly class ActivityTransitionRuntime implements TransitionRuntime
{
    public function __construct(
        private string $snapshotActivity,
        private string $admissionActivity,
        private string $commitActivity,
    ) {
        foreach ([$snapshotActivity, $admissionActivity, $commitActivity] as $activity) {
            if (trim($activity) === '') {
                throw new LogicException('State-machine activity types must not be empty.');
            }
        }
    }

    public function snapshot(
        ExecutionReference $execution,
        TransitionDefinition $transition,
        SnapshotPhase $phase
    ): FactSnapshot {
        $result = Workflow::activity($this->snapshotActivity, [
            'execution' => $execution->toArray(),
            'transition' => get_object_vars($transition),
            'phase' => $phase->value,
        ]);
        return new FactSnapshot(
            $this->reference($result['execution']),
            $result['transitionKey'],
            SnapshotPhase::from($result['phase']),
            $result['snapshotId'],
            $result['state'],
            $result['revision'],
            $this->decision($result['target']),
            $this->decision($result['permission']),
        );
    }

    public function admit(TransitionContext $context): Decision
    {
        $result = Workflow::activity($this->admissionActivity, $this->payload($context));
        if (! $context->execution()->equals($this->reference($result['execution']))) {
            throw new LogicException('The admission belongs to another execution.');
        }
        return $this->decision($result['decision']);
    }

    public function commit(TransitionContext $context): CommitResult
    {
        $result = Workflow::activity($this->commitActivity, $this->payload($context));
        return new CommitResult($this->reference($result['execution']), TransitionStatus::from(
            $result['status']
        ), $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TransitionContext $context): array
    {
        return [
            'execution' => $context->execution()
                ->toArray(),
            'transition' => get_object_vars($context->transition),
            'snapshotId' => $context->snapshot->snapshotId,
            'phase' => $context->snapshot->phase->value,
            'expectedState' => $context->snapshot->state,
            'expectedRevision' => $context->snapshot->revision,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reference(array $payload): ExecutionReference
    {
        return new ExecutionReference(
            $payload['scope'],
            $payload['subject'],
            $payload['executionId'],
            $payload['policyFingerprint'],
            $payload['sourceState'],
            $payload['sourceRevision']
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decision(array $payload): Decision
    {
        return new Decision(Verdict::from($payload['verdict']), $payload['reason'], $payload['evidence']);
    }
}
