<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Machine-readable reason codes attached to a deployment lifecycle
 * blockage. The matching role, the operator API, the CLI, and
 * Waterline all consult this enum so a refusal to promote, drain,
 * resume, or roll back a deployment carries a stable, diagnosable
 * code rather than an opaque error string.
 *
 * Reason codes are deliberately narrow; adding a new value is allowed
 * but renaming or removing one is a contract-level change.
 */
enum DeploymentBlockageReason: string
{
    /**
     * The fleet snapshot reports zero live workers advertising the
     * deployment's required compatibility marker. Promotion would
     * route fresh tasks to a build that nothing can claim.
     */
    case NoCompatibleWorkers = 'no_compatible_workers';

    /**
     * The deployment is already in a draining or drained lifecycle
     * state. Promotion or resume against a draining deployment is
     * refused so operators do not accidentally undo a drain.
     */
    case FleetIsDraining = 'fleet_is_draining';

    /**
     * The deployment's recorded workflow definition fingerprint does
     * not match the fingerprint advertised by the live worker fleet.
     * Promotion would silently migrate runs onto a definition that
     * the recorded fingerprint does not match.
     */
    case FingerprintMismatch = 'fingerprint_mismatch';

    /**
     * `WorkflowModeGuard` (or another replay-safety check) reported
     * an `error` severity issue against a workflow class that this
     * deployment claims to serve. Promotion is refused until the
     * replay-safety guardrail clears.
     */
    case ReplaySafetyFailed = 'replay_safety_failed';

    /**
     * The fleet has at least one live worker but none have produced
     * a heartbeat advertising the deployment's required compatibility
     * marker recently. The check distinguishes "no fleet at all" from
     * "fleet exists, none of them speak this marker."
     */
    case MissingWorkerHeartbeat = 'missing_worker_heartbeat';

    /**
     * The requested transition is invalid for the deployment's
     * current lifecycle state — e.g. promote against a `RolledBack`
     * deployment, resume against a `Drained` deployment, or rollback
     * against a `Pending` deployment that was never promoted.
     */
    case IncompatiblePolicy = 'incompatible_policy';

    /**
     * The deployment itself does not exist (no row in the rollout
     * table). Operators see this when they attempt to act on a
     * deployment by name before the worker fleet has heartbeated.
     */
    case UnknownDeployment = 'unknown_deployment';
}
