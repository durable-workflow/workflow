<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine;

use LogicException;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionBudget;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\Execution\TransitionRuntime;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\StateMachine\Transition\TransitionStep;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/**
 * Composes the four-step business protocol with the existing V2 runtime and native Saga.
 *
 * @experimental The StateMachine API may change in a minor release until its nested slices land.
 */
final class TransitionRunner
{
    /**
     * @param array<string, TransitionStep> $handlers Closed, application-configured type/version registry.
     */
    public function run(
        Workflow $workflow,
        PolicyProfile $profile,
        ExecutionReference $execution,
        string $event,
        TransitionRuntime $runtime,
        array $handlers,
    ): TransitionResult {
        if ($profile->limits === null) {
            return $this->protocol($workflow, $profile, $execution, $event, $runtime, $handlers);
        }
        if (! WorkflowFiberContext::active() || $execution->policyFingerprint !== $profile->fingerprint()) {
            throw new LogicException('A transition budget requires its original active execution and pinned policy.');
        }
        $budget = ExecutionBudget::forExecution($workflow, $execution, $profile->limits);
        try {
            return WorkflowFiberContext::withForwardBudget($budget, fn (): TransitionResult =>
                $this->protocol($workflow, $profile, $execution, $event, $runtime, $handlers));
        } catch (ResourceLimitExceeded $error) {
            if (! $budget->caused($error)) {
                throw $error;
            }
            $candidates = $profile->candidates($event, $execution->sourceState);
            return new TransitionResult(
                $execution,
                TransitionStatus::Blocked,
                $error->reason(),
                transitionKey: count($candidates) === 1 ? $candidates[0]->key : null
            );
        }
    }

    /**
     * @param array<string, TransitionStep> $handlers
     */
    private function protocol(
        Workflow $workflow,
        PolicyProfile $profile,
        ExecutionReference $execution,
        string $event,
        TransitionRuntime $runtime,
        array $handlers,
    ): TransitionResult {
        if (! WorkflowFiberContext::active()) {
            throw new LogicException('Business transitions must execute inside a V2 workflow.');
        }
        if ($execution->policyFingerprint !== $profile->fingerprint()) {
            throw new LogicException('The pinned policy does not match this execution.');
        }
        $candidates = $profile->candidates($event, $execution->sourceState);
        if (count($candidates) !== 1) {
            return new TransitionResult(
                $execution,
                $candidates === [] ? TransitionStatus::Unavailable : TransitionStatus::Ambiguous,
                $candidates === [] ? 'transition_unavailable' : 'transition_ambiguous'
            );
        }
        $transition = $candidates[0];
        $definition = $profile->definition;
        $step = $handlers[$transition->handlerKey()] ?? null;
        if (! $step instanceof TransitionStep) {
            throw new LogicException('The pinned transition handler is not registered.');
        }
        $context = $this->context($runtime, $execution, $transition, SnapshotPhase::Before, $definition);
        if (! $this->sourceMatches($context)) {
            return new TransitionResult(
                $execution,
                TransitionStatus::Conflict,
                'source_changed',
                transitionKey: $transition->key
            );
        }
        $invariant = $this->invariantRefusal($context);
        if ($invariant !== null) {
            return $invariant;
        }
        if (! $this->targetSatisfied($step, $context)) {
            if ($context->snapshot->target->verdict === Verdict::Unknown) {
                return $this->decision($context, $context->snapshot->target, TransitionStatus::Unknown);
            }
            if (! $transition->hasWork) {
                return $this->decision($context, $context->snapshot->target, TransitionStatus::Pending);
            }
            $permitted = $step->canExecute($context);
            if ($permitted !== $context->snapshot->permission->satisfied()) {
                throw new LogicException('The execution guard disagrees with its recorded assessment.');
            }
            if (! $permitted) {
                return $this->decision($context, $context->snapshot->permission, TransitionStatus::Blocked);
            }
            $admission = $runtime->admit($context);
            if (! $admission->satisfied()) {
                return $this->decision($context, $admission, TransitionStatus::Blocked);
            }
            $context = new TransitionContext($transition, $context->snapshot, $admission);
            // Capture the original owner before work can suspend, fail or lose its receipt.
            $workflow->addCompensation(function () use ($runtime, $execution, $transition, $step, $definition): void {
                $current = $this->context($runtime, $execution, $transition, SnapshotPhase::Compensation, $definition);
                $step->compensate($current, $execution);
            });
            $receipt = $step->execute($context);
            if (! $execution->equals($receipt->execution)) {
                throw new LogicException('The execution receipt belongs to another operation.');
            }
            $fresh = $this->context($runtime, $execution, $transition, SnapshotPhase::After, $definition);
            if ($fresh->snapshot->snapshotId === $context->snapshot->snapshotId) {
                throw new LogicException('Post-action evaluation requires a fresh recorded snapshot.');
            }
            $context = $fresh;
            if (! $this->sourceMatches($context)) {
                return new TransitionResult(
                    $execution,
                    TransitionStatus::Conflict,
                    'source_changed',
                    transitionKey: $transition->key
                );
            }
            $invariant = $this->invariantRefusal($context);
            if ($invariant !== null) {
                return $invariant;
            }
            if (! $this->targetSatisfied($step, $context)) {
                return $this->decision($context, $context->snapshot->target, TransitionStatus::Pending);
            }
        }
        $commit = $runtime->commit($context);
        if (! $execution->equals($commit->execution)) {
            throw new LogicException('The commit acknowledgement belongs to another execution.');
        }
        return new TransitionResult(
            $execution,
            $commit->status,
            $commit->reason,
            $context->snapshot->target,
            $transition->key
        );
    }

    private function context(
        TransitionRuntime $runtime,
        ExecutionReference $execution,
        TransitionDefinition $transition,
        SnapshotPhase $phase,
        ?MachineDefinition $definition,
    ): TransitionContext {
        $snapshot = $runtime->snapshot($execution, $transition, $phase);
        if (! $execution->equals(
            $snapshot->execution
        ) || $snapshot->transitionKey !== $transition->key || $snapshot->phase !== $phase) {
            throw new LogicException('The recorded snapshot does not match the requested execution and phase.');
        }
        if (($definition === null && $snapshot->machineAssessment !== null)
            || ($definition !== null && ! ($snapshot->machineAssessment?->matches(
                $definition,
                $snapshot->snapshotId
            ) ?? false))) {
            throw new LogicException('The recorded snapshot lacks its exact pinned machine invariant assessment.');
        }
        return new TransitionContext($transition, $snapshot);
    }

    private function invariantRefusal(TransitionContext $context): ?TransitionResult
    {
        $assessment = $context->snapshot->machineAssessment;
        return $assessment === null || $assessment->decision->satisfied() ? null
            : $this->decision($context, $assessment->decision, TransitionStatus::Blocked);
    }

    private function sourceMatches(TransitionContext $context): bool
    {
        $execution = $context->execution();

        return $context->snapshot->state === $execution->sourceState
            && $context->snapshot->revision === $execution->sourceRevision;
    }

    private function targetSatisfied(TransitionStep $step, TransitionContext $context): bool
    {
        $satisfied = $step->canTransition($context);
        if ($satisfied !== $context->snapshot->target->satisfied()) {
            throw new LogicException('The target guard disagrees with its recorded assessment.');
        }
        return $satisfied;
    }

    private function decision(
        TransitionContext $context,
        Decision $decision,
        TransitionStatus $status
    ): TransitionResult {
        return new TransitionResult(
            $context->execution(),
            $decision->verdict === Verdict::Unknown ? TransitionStatus::Unknown : $status,
            $decision->reason,
            $decision,
            $context->transition->key
        );
    }
}
