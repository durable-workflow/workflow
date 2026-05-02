<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\DeploymentBlockageReason;
use Workflow\V2\Enums\DeploymentLifecycleState;

/**
 * Pure planner that connects worker-deployment lifecycle transitions
 * (promote, drain, resume, rollback) to the fleet snapshot, the
 * recorded workflow definition fingerprint, and the replay-safety
 * guardrail. The planner returns a list of {@see DeploymentBlockage}
 * records explaining exactly why a transition is unsafe — never
 * `false` or an opaque string — so the CLI, Waterline, and cloud all
 * read the same diagnosis.
 *
 * Callers wire in the fleet snapshot. The signature deliberately
 * avoids reading `WorkerCompatibilityFleet` directly so unit tests can
 * exercise the planner deterministically and so cloud may project a
 * remote fleet snapshot through the same evaluator.
 *
 * @api Stable class surface consumed by the standalone
 *      workflow-server, the CLI, and Waterline. Public method
 *      signatures on this class are covered by the workflow
 *      package's semver guarantee. See docs/api-stability.md.
 */
final class DeploymentLifecyclePlan
{
    /**
     * Snapshot describing the live fleet view the planner needs.
     *
     * @param array{
     *     active_worker_count: int,
     *     active_workers_supporting_required: int,
     *     advertised_compatibility: list<string>,
     *     advertised_fingerprints: list<string>,
     *     replay_safety_severity: string|null,
     *     replay_safety_messages?: list<string>
     * } $fleet
     * @return list<DeploymentBlockage>
     */
    public static function evaluatePromote(WorkerDeployment $deployment, array $fleet): array
    {
        $blockages = [];

        if ($deployment->state->isTerminal()) {
            $blockages[] = new DeploymentBlockage(
                reason: DeploymentBlockageReason::IncompatiblePolicy,
                message: sprintf(
                    'Deployment %s is %s and cannot be promoted; recreate the deployment first.',
                    $deployment->name(),
                    $deployment->state->value,
                ),
                scope: self::scopeOf($deployment),
                expectedResolution: 'Create a new active deployment for this build id, then promote it.',
            );

            return $blockages;
        }

        if ($deployment->state === DeploymentLifecycleState::Draining) {
            $blockages[] = new DeploymentBlockage(
                reason: DeploymentBlockageReason::FleetIsDraining,
                message: sprintf(
                    'Deployment %s is draining; resume it before promoting.',
                    $deployment->name(),
                ),
                scope: self::scopeOf($deployment),
                expectedResolution: 'Resume the deployment, then retry promotion.',
            );
        }

        $supportsRequired = (int) ($fleet['active_workers_supporting_required'] ?? 0);
        $activeWorkers = (int) ($fleet['active_worker_count'] ?? 0);

        if ($deployment->requiredCompatibility !== null) {
            if ($activeWorkers === 0) {
                $blockages[] = new DeploymentBlockage(
                    reason: DeploymentBlockageReason::NoCompatibleWorkers,
                    message: sprintf(
                        'No workers are heartbeating against %s/%s; promotion would route fresh tasks to a fleet that cannot claim them.',
                        $deployment->namespace,
                        $deployment->taskQueue,
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'Start at least one worker that advertises compatibility ['
                        . $deployment->requiredCompatibility
                        . '] for this task queue, then retry promotion.',
                );
            } elseif ($supportsRequired === 0) {
                $blockages[] = new DeploymentBlockage(
                    reason: DeploymentBlockageReason::MissingWorkerHeartbeat,
                    message: sprintf(
                        'Fleet has %d active worker(s) on %s/%s but none advertise required compatibility [%s]; advertised markers: [%s].',
                        $activeWorkers,
                        $deployment->namespace,
                        $deployment->taskQueue,
                        $deployment->requiredCompatibility,
                        implode(', ', self::stringList($fleet['advertised_compatibility'] ?? [])),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'Roll a worker that supports compatibility ['
                        . $deployment->requiredCompatibility
                        . '] before promoting.',
                );
            }
        }

        if ($deployment->recordedFingerprint !== null) {
            $advertised = self::stringList($fleet['advertised_fingerprints'] ?? []);

            if ($advertised !== [] && ! in_array($deployment->recordedFingerprint, $advertised, true)) {
                $blockages[] = new DeploymentBlockage(
                    reason: DeploymentBlockageReason::FingerprintMismatch,
                    message: sprintf(
                        'Recorded workflow definition fingerprint [%s] is not advertised by the live fleet (advertised: [%s]).',
                        $deployment->recordedFingerprint,
                        implode(', ', $advertised),
                    ),
                    scope: self::scopeOf($deployment, [
                        'recorded_fingerprint' => $deployment->recordedFingerprint,
                    ]),
                    expectedResolution: 'Either roll a worker advertising the recorded fingerprint or recreate the deployment with the new fingerprint.',
                );
            }
        }

        $replaySeverity = $fleet['replay_safety_severity'] ?? null;

        if ($replaySeverity === 'error') {
            $messages = self::stringList($fleet['replay_safety_messages'] ?? []);
            $blockages[] = new DeploymentBlockage(
                reason: DeploymentBlockageReason::ReplaySafetyFailed,
                message: $messages === []
                    ? 'WorkflowModeGuard reported an error-severity replay-safety issue against the deployment\'s workflow types.'
                    : 'Replay-safety guardrail reported error-severity issues: ' . implode(' | ', $messages),
                scope: self::scopeOf($deployment),
                expectedResolution: 'Resolve the WorkflowModeGuard issue or downgrade DW_V2_GUARDRAILS_BOOT to warn before promoting.',
            );
        }

        return $blockages;
    }

    /**
     * @param array{
     *     in_flight_workflow_count?: int,
     *     in_flight_activity_count?: int
     * } $fleet
     * @return list<DeploymentBlockage>
     */
    public static function evaluateDrain(WorkerDeployment $deployment, array $fleet = []): array
    {
        if ($deployment->state === DeploymentLifecycleState::RolledBack) {
            return [
                new DeploymentBlockage(
                    reason: DeploymentBlockageReason::IncompatiblePolicy,
                    message: sprintf(
                        'Deployment %s is rolled back; drain is unnecessary because the deployment no longer accepts work.',
                        $deployment->name(),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'No action required; the deployment is already terminal.',
                ),
            ];
        }

        return [];
    }

    /**
     * @param array{
     *     active_worker_count?: int,
     *     active_workers_supporting_required?: int
     * } $fleet
     * @return list<DeploymentBlockage>
     */
    public static function evaluateResume(WorkerDeployment $deployment, array $fleet = []): array
    {
        if ($deployment->state === DeploymentLifecycleState::Drained) {
            return [
                new DeploymentBlockage(
                    reason: DeploymentBlockageReason::IncompatiblePolicy,
                    message: sprintf(
                        'Deployment %s is fully drained; resume is not allowed once a deployment has reached the drained terminal state — recreate it instead.',
                        $deployment->name(),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'Create a new active deployment for this build id.',
                ),
            ];
        }

        if ($deployment->state === DeploymentLifecycleState::RolledBack) {
            return [
                new DeploymentBlockage(
                    reason: DeploymentBlockageReason::IncompatiblePolicy,
                    message: sprintf(
                        'Deployment %s is rolled back; promote a different deployment rather than resuming this one.',
                        $deployment->name(),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'Promote the prior healthy deployment, or create a new active deployment for this build id.',
                ),
            ];
        }

        if ($deployment->state !== DeploymentLifecycleState::Draining) {
            // Resuming an already-active deployment is a no-op rather
            // than a blockage; callers may invoke resume idempotently.
            return [];
        }

        return [];
    }

    /**
     * Rolling back is the inverse of promotion: it surrenders the
     * promoted slot. A rollback against a deployment that was never
     * promoted is incompatible.
     *
     * @return list<DeploymentBlockage>
     */
    public static function evaluateRollback(WorkerDeployment $deployment): array
    {
        if ($deployment->state === DeploymentLifecycleState::Pending) {
            return [
                new DeploymentBlockage(
                    reason: DeploymentBlockageReason::IncompatiblePolicy,
                    message: sprintf(
                        'Deployment %s has never been promoted; nothing to roll back.',
                        $deployment->name(),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'Promote the deployment first, or drain it directly if you want to remove it.',
                ),
            ];
        }

        if ($deployment->state === DeploymentLifecycleState::RolledBack) {
            return [
                new DeploymentBlockage(
                    reason: DeploymentBlockageReason::IncompatiblePolicy,
                    message: sprintf(
                        'Deployment %s is already rolled back.',
                        $deployment->name(),
                    ),
                    scope: self::scopeOf($deployment),
                    expectedResolution: 'No action required; the deployment is already terminal.',
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, scalar|null> $additional
     * @return array<string, scalar|null>
     */
    private static function scopeOf(WorkerDeployment $deployment, array $additional = []): array
    {
        $scope = [
            'namespace' => $deployment->namespace,
            'task_queue' => $deployment->taskQueue,
            'build_id' => $deployment->buildId,
            'state' => $deployment->state->value,
        ];

        if ($deployment->requiredCompatibility !== null) {
            $scope['required_compatibility'] = $deployment->requiredCompatibility;
        }

        if ($deployment->workflowTypes !== []) {
            $scope['workflow_types'] = implode(',', $deployment->workflowTypes);
        }

        return [...$scope, ...$additional];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $strings[] = $entry;
        }

        return $strings;
    }
}
