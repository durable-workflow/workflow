<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Workflow\V2\Enums\DeploymentLifecycleState;
use Workflow\V2\Enums\WorkflowCompatibilityPolicy;

/**
 * First-class value object representing a worker deployment.
 *
 * A deployment is the long-lived envelope around a `(namespace,
 * task_queue, build_id)` cohort. It records the lifecycle state, the
 * compatibility policy that governs whether long-lived runs may
 * migrate forward, the workflow types or namespace bindings the
 * deployment serves, and the audit fields the lifecycle surface
 * (`promote`, `drain`, `resume`, `rollback`) needs.
 *
 * Server-controlled rollout machinery, the matching role, the CLI,
 * and Waterline all consume this shape rather than re-deriving it
 * from the raw build-id rollout rows. Construct via the named
 * constructors so the validation lives in one place.
 *
 * @api Stable class surface consumed by the standalone
 *      workflow-server, the CLI, and Waterline. Public method
 *      signatures on this class are covered by the workflow
 *      package's semver guarantee. See docs/api-stability.md.
 */
final class WorkerDeployment
{
    /**
     * @param list<string> $workflowTypes
     */
    private function __construct(
        public readonly string $namespace,
        public readonly string $taskQueue,
        public readonly ?string $buildId,
        public readonly DeploymentLifecycleState $state,
        public readonly WorkflowCompatibilityPolicy $compatibilityPolicy,
        public readonly ?string $requiredCompatibility,
        public readonly ?string $recordedFingerprint,
        public readonly array $workflowTypes,
        public readonly ?CarbonInterface $promotedAt,
        public readonly ?CarbonInterface $drainedAt,
        public readonly ?CarbonInterface $rolledBackAt,
    ) {}

    /**
     * Construct a deployment for an active build cohort.
     *
     * @param list<string> $workflowTypes
     */
    public static function forActiveBuild(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        ?string $requiredCompatibility = null,
        ?string $recordedFingerprint = null,
        WorkflowCompatibilityPolicy $compatibilityPolicy = WorkflowCompatibilityPolicy::Pinned,
        array $workflowTypes = [],
    ): self {
        return new self(
            namespace: self::normalize($namespace, 'namespace'),
            taskQueue: self::normalize($taskQueue, 'taskQueue'),
            buildId: self::normalizeNullable($buildId),
            state: DeploymentLifecycleState::Active,
            compatibilityPolicy: $compatibilityPolicy,
            requiredCompatibility: self::normalizeNullable($requiredCompatibility),
            recordedFingerprint: self::normalizeNullable($recordedFingerprint),
            workflowTypes: self::normalizeWorkflowTypes($workflowTypes),
            promotedAt: null,
            drainedAt: null,
            rolledBackAt: null,
        );
    }

    /**
     * Reconstruct a deployment from a persisted rollout row plus a
     * compatibility policy decision. The data array follows the same
     * shape as the standalone server's `workflow_worker_build_id_rollouts`
     * row so server callers can pass the row in directly.
     *
     * @param array{
     *     namespace: string,
     *     task_queue: string,
     *     build_id?: string|null,
     *     drain_intent?: string|null,
     *     drained_at?: \Carbon\CarbonInterface|\DateTimeInterface|string|null,
     *     promoted_at?: \Carbon\CarbonInterface|\DateTimeInterface|string|null,
     *     rolled_back_at?: \Carbon\CarbonInterface|\DateTimeInterface|string|null,
     *     required_compatibility?: string|null,
     *     recorded_fingerprint?: string|null,
     *     workflow_types?: list<string>,
     *     compatibility_policy?: string|null
     * } $row
     */
    public static function fromRolloutRow(array $row, ?DeploymentLifecycleState $stateOverride = null): self
    {
        $namespace = self::normalize((string) ($row['namespace'] ?? ''), 'namespace');
        $taskQueue = self::normalize((string) ($row['task_queue'] ?? ''), 'taskQueue');
        $buildId = self::normalizeNullable($row['build_id'] ?? null);

        $drainIntent = self::normalizeNullable($row['drain_intent'] ?? null);
        $drainedAt = self::normalizeCarbon($row['drained_at'] ?? null);
        $promotedAt = self::normalizeCarbon($row['promoted_at'] ?? null);
        $rolledBackAt = self::normalizeCarbon($row['rolled_back_at'] ?? null);

        $state = $stateOverride ?? self::deriveState($drainIntent, $drainedAt, $promotedAt, $rolledBackAt);

        $policy = self::resolveCompatibilityPolicy($row['compatibility_policy'] ?? null);

        return new self(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            state: $state,
            compatibilityPolicy: $policy,
            requiredCompatibility: self::normalizeNullable($row['required_compatibility'] ?? null),
            recordedFingerprint: self::normalizeNullable($row['recorded_fingerprint'] ?? null),
            workflowTypes: self::normalizeWorkflowTypes($row['workflow_types'] ?? []),
            promotedAt: $promotedAt,
            drainedAt: $drainedAt,
            rolledBackAt: $rolledBackAt,
        );
    }

    /**
     * Stable identifier used by operator tooling. Composed of the
     * namespace, task queue, and build id (or `unversioned` when no
     * build id is recorded). The CLI and Waterline both display this
     * verbatim.
     */
    public function name(): string
    {
        return sprintf(
            '%s/%s@%s',
            $this->namespace,
            $this->taskQueue,
            $this->buildId ?? 'unversioned',
        );
    }

    public function isPromoted(): bool
    {
        return $this->state === DeploymentLifecycleState::Promoted;
    }

    public function isDraining(): bool
    {
        return $this->state === DeploymentLifecycleState::Draining
            || $this->state === DeploymentLifecycleState::Drained;
    }

    public function acceptsNewWork(): bool
    {
        return $this->state->acceptsNewWork();
    }

    public function withState(DeploymentLifecycleState $state, ?CarbonInterface $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self(
            namespace: $this->namespace,
            taskQueue: $this->taskQueue,
            buildId: $this->buildId,
            state: $state,
            compatibilityPolicy: $this->compatibilityPolicy,
            requiredCompatibility: $this->requiredCompatibility,
            recordedFingerprint: $this->recordedFingerprint,
            workflowTypes: $this->workflowTypes,
            promotedAt: $state === DeploymentLifecycleState::Promoted ? $now : $this->promotedAt,
            drainedAt: in_array($state, [
                DeploymentLifecycleState::Draining,
                DeploymentLifecycleState::Drained,
            ], true) ? ($this->drainedAt ?? $now) : null,
            rolledBackAt: $state === DeploymentLifecycleState::RolledBack ? $now : $this->rolledBackAt,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     namespace: string,
     *     task_queue: string,
     *     build_id: string|null,
     *     state: string,
     *     accepts_new_work: bool,
     *     compatibility_policy: string,
     *     required_compatibility: string|null,
     *     recorded_fingerprint: string|null,
     *     workflow_types: list<string>,
     *     promoted_at: string|null,
     *     drained_at: string|null,
     *     rolled_back_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'namespace' => $this->namespace,
            'task_queue' => $this->taskQueue,
            'build_id' => $this->buildId,
            'state' => $this->state->value,
            'accepts_new_work' => $this->acceptsNewWork(),
            'compatibility_policy' => $this->compatibilityPolicy->value,
            'required_compatibility' => $this->requiredCompatibility,
            'recorded_fingerprint' => $this->recordedFingerprint,
            'workflow_types' => $this->workflowTypes,
            'promoted_at' => $this->promotedAt?->toIso8601String(),
            'drained_at' => $this->drainedAt?->toIso8601String(),
            'rolled_back_at' => $this->rolledBackAt?->toIso8601String(),
        ];
    }

    private static function deriveState(
        ?string $drainIntent,
        ?CarbonInterface $drainedAt,
        ?CarbonInterface $promotedAt,
        ?CarbonInterface $rolledBackAt,
    ): DeploymentLifecycleState {
        if ($rolledBackAt !== null) {
            return DeploymentLifecycleState::RolledBack;
        }

        if ($drainIntent === 'draining' || $drainedAt !== null) {
            return $drainIntent === 'drained' ? DeploymentLifecycleState::Drained : DeploymentLifecycleState::Draining;
        }

        if ($drainIntent === 'drained') {
            return DeploymentLifecycleState::Drained;
        }

        if ($promotedAt !== null) {
            return DeploymentLifecycleState::Promoted;
        }

        return DeploymentLifecycleState::Active;
    }

    private static function resolveCompatibilityPolicy(mixed $value): WorkflowCompatibilityPolicy
    {
        if ($value instanceof WorkflowCompatibilityPolicy) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $resolved = WorkflowCompatibilityPolicy::tryFrom($value);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return WorkflowCompatibilityPolicy::Pinned;
    }

    private static function normalize(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf(
                'WorkerDeployment requires a non-empty %s.',
                $field,
            ));
        }

        return $value;
    }

    private static function normalizeNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeCarbon(mixed $value): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeWorkflowTypes(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $types = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $types[$entry] = true;
        }

        $types = array_keys($types);
        sort($types);

        /** @var list<string> $types */
        return $types;
    }
}
