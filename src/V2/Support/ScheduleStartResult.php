<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Value object returned when a schedule starts a workflow run, exposing the
 * caller's instance id and the assigned run id.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public constructor and readonly property names on this class are
 *      covered by the workflow package's semver guarantee. See
 *      docs/api-stability.md.
 */
final class ScheduleStartResult
{
    public function __construct(
        public readonly string $instanceId,
        public readonly ?string $runId,
    ) {}
}
