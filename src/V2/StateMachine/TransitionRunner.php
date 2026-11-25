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
use Workflow\V2\StateMachine\Execution\TransitionStage;
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
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
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
        $limits = $profile->limits();
        if ($limits === null) {
            return $this->protocol($workflow, $profile, $execution, $event, $runtime, $handlers);
        }
        if (! WorkflowFiberContext::active() || $execution->policyFingerprint !== $profile->fingerprint()) {
            throw new LogicException('A transition budget requires its original active execution and pinned policy.');
        }
        $budget = ExecutionBudget::forExecution($workflow, $execution, $limits);
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
                transitionKey: count($candidates) === 1 ? $candidates[0]->key : null,
                stage: TransitionStage::Budget,
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
                $candidates === [] ? 'transition_unavailable' : 'transition_ambiguous',
                stage: TransitionStage::Selection,
            );
        }
        $transition = $candidates[0];
        $definition = $profile->definition;
        $step = $handlers[$transition->handlerKey()] ?? null;
        if (! $step instanceof TransitionStep) {
            throw new LogicException('The pinned transition handler is not registered.');
        }
        $context = $this->context($runtime, $execution, $transition, SnapshotPhase::Before, $definition);
        $refusal = $this->snapshotRefusal($context);
        return $refusal ?? $this->transitionWork($workflow, $runtime, $step, $context, $definition);
    }

    private function transitionWork(
        Workflow $workflow,
        TransitionRuntime $runtime,
        TransitionStep $step,
        TransitionContext $context,
        ?MachineDefinition $definition,
    ): TransitionResult {
        if ($this->targetSatisfied($step, $context)) {
            return $this->commit($runtime, $context);
        }
        $refusal = $this->workRefusal($step, $context);
        return $refusal ?? $this->admitAndExecute($workflow, $runtime, $step, $context, $definition);
    }

    private function workRefusal(TransitionStep $step, TransitionContext $context): ?TransitionResult
    {
        if ($context->snapshot->target->verdict === Verdict::Unknown) {
            return $this->decision(
                $context,
                $context->snapshot->target,
                TransitionStatus::Unknown,
                TransitionStage::Target
            );
        }
        if (! $context->transition->hasWork) {
            return $this->decision(
                $context,
                $context->snapshot->target,
                TransitionStatus::Pending,
                TransitionStage::Target
            );
        }
        $permitted = $step->canExecute($context);
        if ($permitted !== $context->snapshot->permission->satisfied()) {
            throw new LogicException('The execution guard disagrees with its recorded assessment.');
        }
        return $permitted ? null : $this->decision(
            $context,
            $context->snapshot->permission,
            TransitionStatus::Blocked,
            TransitionStage::Permission
        );
    }

    private function admitAndExecute(
        Workflow $workflow,
        TransitionRuntime $runtime,
        TransitionStep $step,
        TransitionContext $context,
        ?MachineDefinition $definition,
    ): TransitionResult {
        $admission = $runtime->admit($context);
        if (! $admission->satisfied()) {
            return $this->decision($context, $admission, TransitionStatus::Blocked, TransitionStage::Admission);
        }
        $execution = $context->execution();
        $transition = $context->transition;
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
        $refusal = $this->snapshotRefusal($fresh);
        if ($refusal !== null) {
            return $refusal;
        }
        return $this->targetSatisfied($step, $fresh) ? $this->commit($runtime, $fresh)
            : $this->decision($fresh, $fresh->snapshot->target, TransitionStatus::Pending, TransitionStage::Target);
    }

    private function snapshotRefusal(TransitionContext $context): ?TransitionResult
    {
        return $this->sourceMatches($context) ? $this->invariantRefusal($context)
            : new TransitionResult(
                $context->execution(),
                TransitionStatus::Conflict,
                'source_changed',
                transitionKey: $context->transition->key,
                stage: TransitionStage::Source,
                snapshotId: $context->snapshot->snapshotId,
                phase: $context->snapshot->phase,
            );
    }

    private function commit(TransitionRuntime $runtime, TransitionContext $context): TransitionResult
    {
        $commit = $runtime->commit($context);
        $execution = $context->execution();
        if (! $execution->equals($commit->execution)) {
            throw new LogicException('The commit acknowledgement belongs to another execution.');
        }
        return new TransitionResult(
            $execution,
            $commit->status,
            $commit->reason,
            $context->snapshot->target,
            $context->transition->key,
            TransitionStage::Commit,
            $context->snapshot->snapshotId,
            $context->snapshot->phase,
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
            : $this->decision($context, $assessment->decision, TransitionStatus::Blocked, TransitionStage::Invariant);
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
        TransitionStatus $status,
        TransitionStage $stage,
    ): TransitionResult {
        return new TransitionResult(
            $context->execution(),
            $decision->verdict === Verdict::Unknown ? TransitionStatus::Unknown : $status,
            $decision->reason,
            $decision,
            $context->transition->key,
            $stage,
            $context->snapshot->snapshotId,
            $context->snapshot->phase,
        );
    }
}
