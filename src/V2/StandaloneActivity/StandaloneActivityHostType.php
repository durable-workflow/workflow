<?php

declare(strict_types=1);

namespace Workflow\V2\StandaloneActivity;

use Workflow\V2\Models\WorkflowRun;

/**
 * Marker for the server-managed host run that anchors a standalone activity.
 *
 * A standalone activity is exposed to callers as a top-level durable job,
 * but internally each one is recorded inside its own workflow run so that
 * activity dispatch, retry, timeout enforcement, cancellation, and history
 * projection continue to flow through the existing activity infrastructure.
 *
 * Host runs are created with both `workflow_type` and `workflow_class`
 * set to the {@see self::WORKFLOW_TYPE} key — there is no PHP workflow
 * class behind them. The server never schedules a workflow task for the
 * host run; the activity execution drives the run's lifecycle directly.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The WORKFLOW_TYPE constant and isHostRun() signature are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class StandaloneActivityHostType
{
    /**
     * Durable workflow type key used for every standalone activity host
     * run. Run-summary listings and the listing API filter on this key
     * to surface standalone activities as a first-class top-level entity.
     */
    public const WORKFLOW_TYPE = 'dw.standalone_activity';

    /**
     * Determine whether the supplied run is the host of a standalone
     * activity. Used by activity outcome and timeout paths to close the host
     * run on terminal activity outcome instead of scheduling a workflow-task
     * resume row.
     */
    public static function isHostRun(?WorkflowRun $run): bool
    {
        return $run !== null
            && (
                $run->workflow_type === self::WORKFLOW_TYPE
                || $run->workflow_class === self::WORKFLOW_TYPE
            );
    }
}
