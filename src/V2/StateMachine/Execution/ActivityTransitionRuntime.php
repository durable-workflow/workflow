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
        $result = $this->shaped(Workflow::activity($this->snapshotActivity, [
            'execution' => $execution->toArray(),
            'transition' => get_object_vars($transition),
            'phase' => $phase->value,
        ]), [
            'execution',
            'transitionKey',
            'phase',
            'snapshotId',
            'state',
            'revision',
            'target',
            'permission',
        ], 'snapshot');
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
        $result = $this->shaped(
            Workflow::activity($this->admissionActivity, $this->payload($context)),
            ['execution', 'decision'],
            'admission'
        );
        if (! $context->execution()->equals($this->reference($result['execution']))) {
            throw new LogicException('The admission belongs to another execution.');
        }
        return $this->decision($result['decision']);
    }

    public function commit(TransitionContext $context): CommitResult
    {
        $result = $this->shaped(
            Workflow::activity($this->commitActivity, $this->payload($context)),
            ['execution', 'status', 'reason'],
            'commit'
        );
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
     * An adapter result missing a required field is refused explicitly, not read as a warning or a null.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function shaped(mixed $result, array $keys, string $boundary): array
    {
        if (! is_array($result)) {
            throw new LogicException("The {$boundary} activity must return an object.");
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $result)) {
                throw new LogicException("The {$boundary} activity result is missing {$key}.");
            }
        }
        foreach (['execution', 'target', 'permission', 'decision'] as $nested) {
            if (array_key_exists($nested, $result) && ! is_array($result[$nested])) {
                throw new LogicException("The {$boundary} activity result field {$nested} must be an object.");
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reference(array $payload): ExecutionReference
    {
        foreach (['scope', 'subject', 'executionId', 'policyFingerprint', 'sourceState', 'sourceRevision'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new LogicException("The execution reference is missing {$key}.");
            }
        }
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
        foreach (['verdict', 'reason', 'evidence'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new LogicException("The recorded decision is missing {$key}.");
            }
        }
        return new Decision(Verdict::from($payload['verdict']), $payload['reason'], $payload['evidence']);
    }
}
