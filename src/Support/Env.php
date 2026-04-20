<?php

declare(strict_types=1);

namespace Workflow\Support;

/**
 * Reads operator-facing env vars for the Durable Workflow package with a
 * DW_*-primary / WORKFLOW_*-legacy fallback.
 *
 * The DW_* name is the documented public contract for the Durable Workflow
 * server image (see durable-workflow/server config/dw-contract.php); the
 * legacy WORKFLOW_* / ACTIVITY_* names are honored as backward-compatible
 * aliases. Callers should prefer the DW_* name going forward.
 *
 * Mirrors the shape of App\Support\EnvAuditor::env in the server repo so a
 * single rename happens in one place across the workflow + server surfaces.
 */
final class Env
{
    /**
     * Resolve an env var, preferring the DW_* primary name and falling back
     * to its documented legacy counterpart. Returns $default when neither
     * name is set.
     *
     * The underlying env() call in Laravel already converts the literal
     * strings "true" / "false" / "null" / "(empty)" to their typed values
     * and strips surrounding quotes, so callers should continue using the
     * same (int), (bool) casts they used before.
     */
    public static function dw(string $name, string $legacy, mixed $default = null): mixed
    {
        $primary = env($name, null);
        if ($primary !== null) {
            return $primary;
        }

        return env($legacy, $default);
    }
}
