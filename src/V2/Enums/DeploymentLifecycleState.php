<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Lifecycle states a worker deployment may be in. Operators promote,
 * drain, resume, and roll back deployments through this state machine
 * rather than mutating ad hoc build-id rows directly.
 *
 * The states form a small DAG:
 *
 *   Pending ──promote──> Active ──promote──> Promoted
 *      │                   │                    │
 *      │                   └─drain──> Draining ─drain─> Drained
 *      │                   │                    │
 *      │                   └────────resume──────┘  (back to Active)
 *      │                                       │
 *      └──────────rollback to Active or Promoted of a prior deployment
 *
 * `RolledBack` records that an operator (or an automatic safety check)
 * decided this deployment is no longer the promoted one. The deployment
 * row stays around so the rollback decision is auditable.
 */
enum DeploymentLifecycleState: string
{
    case Pending = 'pending';

    case Active = 'active';

    case Promoted = 'promoted';

    case Draining = 'draining';

    case Drained = 'drained';

    case RolledBack = 'rolled_back';

    /**
     * The set of lifecycle states that can still claim new work. The
     * matching role consults this when deciding whether a deployment is
     * eligible to receive a freshly produced task.
     */
    public function acceptsNewWork(): bool
    {
        return match ($this) {
            self::Pending,
            self::Active,
            self::Promoted => true,
            self::Draining,
            self::Drained,
            self::RolledBack => false,
        };
    }

    /**
     * Whether the state represents a terminal lifecycle. Terminal
     * deployments cannot be promoted or resumed without being explicitly
     * recreated.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Drained,
            self::RolledBack => true,
            default => false,
        };
    }
}
