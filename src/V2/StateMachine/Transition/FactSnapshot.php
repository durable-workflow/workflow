<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

use InvalidArgumentException;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;

/** Recorded assessments reference application-owned facts. No mutable entities cross this boundary. */
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
    ) {
        foreach ([$transitionKey, $snapshotId, $state, $revision] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Snapshot identity fields must not be empty.');
            }
        }
    }
}
