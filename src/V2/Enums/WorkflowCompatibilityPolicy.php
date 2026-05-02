<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Long-lived workflow compatibility policy attached to a worker
 * deployment. The policy decides what happens to runs already in
 * flight when the deployment they were started against changes
 * compatibility marker, build id, or workflow definition fingerprint.
 *
 * `Pinned` is the default safe posture: the run keeps replaying on
 * the deployment's recorded fingerprint and refuses to migrate to a
 * newer build. `AutoUpgrade` opts the workflow type into single-step
 * compatibility upgrades — the matching role is allowed to route the
 * next workflow task to a worker that advertises the next compatible
 * marker even if the run was started against an older deployment.
 *
 * The policy is per-deployment so different workflow types in the
 * same namespace can opt in independently.
 */
enum WorkflowCompatibilityPolicy: string
{
    case Pinned = 'pinned';

    case AutoUpgrade = 'auto_upgrade';

    /**
     * Whether this policy allows a run to migrate to a newer
     * deployment within the single-step compatibility window.
     */
    public function allowsAutoUpgrade(): bool
    {
        return $this === self::AutoUpgrade;
    }

    /**
     * Whether this policy requires the matching role to refuse
     * claims whose worker fingerprint does not match the run's
     * recorded fingerprint.
     */
    public function requiresFingerprintPin(): bool
    {
        return $this === self::Pinned;
    }
}
