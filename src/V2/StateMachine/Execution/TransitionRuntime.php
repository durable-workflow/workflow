<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Persistence\CommitResult;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/**
 * Implement I/O through recorded DW activities, not direct I/O in workflow code.
 * Portable activity results are decoded into these in-memory types by the adapter.
 */
interface TransitionRuntime
{
    /**
     * Record fact provenance and rule versions under the pinned policy, then return assessments.
     */
    public function snapshot(
        ExecutionReference $execution,
        TransitionDefinition $transition,
        SnapshotPhase $phase
    ): FactSnapshot;

    /**
     * Re-establish live authority and admit the original operation idempotently.
     * Application activities enforce the returned capability/fence before effects.
     * A database fence cannot recall an already-issued provider request.
     */
    public function admit(TransitionContext $context): Decision;

    /**
     * Compare state/revision, authority and cancellation atomically with the business decision
     * and publication intent where they share a store. Resolve lost acknowledgement using the
     * same execution identity. Separate stores require an explicit durable handoff.
     */
    public function commit(TransitionContext $context): CommitResult;
}
