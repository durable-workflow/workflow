<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;

/**
 * Resolves the effective workflow-task lease duration for every execution path.
 *
 * @api Stable class surface consumed by embedded hosts and the standalone
 *      workflow-server. The configuration key, constant, and public static
 *      method signatures are covered by the workflow package's semver
 *      guarantee. See docs/api-stability.md.
 */
final class WorkflowTaskLease
{
    public const CONFIG_KEY = 'workflows.v2.workflow_task_lease_seconds';

    public const DEFAULT_SECONDS = 300;

    public static function seconds(): int
    {
        $value = config(self::CONFIG_KEY, self::DEFAULT_SECONDS);

        if (! is_numeric($value) || (int) $value < 1) {
            return self::DEFAULT_SECONDS;
        }

        return (int) $value;
    }

    public static function expiresAt(?CarbonInterface $now = null): CarbonInterface
    {
        return ($now ?? now())
            ->copy()
            ->addSeconds(self::seconds());
    }
}
