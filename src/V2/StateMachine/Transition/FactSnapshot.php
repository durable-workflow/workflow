<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

use InvalidArgumentException;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;

/**
 * Recorded assessments reference application-owned facts. No mutable entities cross this boundary.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class FactSnapshot
{
    public function __construct(
        public ExecutionReference $execution,
        public string $transitionKey,
        public SnapshotPhase $phase,
        public string $snapshotId,
        public string $state,
        public string $revision,
        public Decision $target,
        public Decision $permission,
        public ?MachineAssessment $machineAssessment = null,
    ) {
        foreach ([$transitionKey, $snapshotId, $state, $revision] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Snapshot identity fields must not be empty.');
            }
        }
        if ($machineAssessment !== null && $machineAssessment->snapshotId !== $snapshotId) {
            throw new InvalidArgumentException('The machine invariant assessment belongs to another fact snapshot.');
        }
    }
}
