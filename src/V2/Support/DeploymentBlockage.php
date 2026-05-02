<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\DeploymentBlockageReason;

/**
 * Machine-readable diagnosis of why a deployment lifecycle action
 * (promote, drain, resume, rollback) cannot proceed safely.
 *
 * Every blockage carries:
 *   - a stable `reason` enum value the CLI/Waterline/cloud pin against
 *   - a human-readable `message` that names the affected scope
 *   - a `scope` map containing the namespace, task queue, build id,
 *     workflow type, or required compatibility marker the blockage
 *     applies to so operator UIs can route the diagnosis to the right
 *     view without parsing the message
 *   - an `expected_resolution` description so an operator (or an
 *     agent) knows the concrete next step
 *
 * @api Stable class surface consumed by the standalone
 *      workflow-server, the CLI, and Waterline. Public method
 *      signatures on this class are covered by the workflow
 *      package's semver guarantee. See docs/api-stability.md.
 */
final class DeploymentBlockage
{
    /**
     * @param array<string, scalar|null> $scope
     */
    public function __construct(
        public readonly DeploymentBlockageReason $reason,
        public readonly string $message,
        public readonly array $scope = [],
        public readonly ?string $expectedResolution = null,
    ) {}

    /**
     * @return array{
     *     reason: string,
     *     message: string,
     *     scope: array<string, scalar|null>,
     *     expected_resolution: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'message' => $this->message,
            'scope' => $this->scope,
            'expected_resolution' => $this->expectedResolution,
        ];
    }
}
