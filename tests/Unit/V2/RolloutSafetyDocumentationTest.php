<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 rollout safety and coordination health contract
 * documented in docs/architecture/rollout-safety.md. The doc is the
 * single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server deployment guidance, cloud capacity planning,
 * and test coverage for boot-time admission, mixed-build safety,
 * schema fencing, routing drains, stuck detectors, coordination
 * health metrics, Waterline surfaces, and the config surface.
 * Changes to any named guarantee must update this test and the
 * documented contract in the same change so drift is reviewed
 * deliberately.
 */
final class RolloutSafetyDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/rollout-safety.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Rollout Safety and Coordination Health Contract',
        '## Scope',
        '## Terminology',
        '## Admission authority and mixed-build safety',
        '### Boot-time admission',
        '### Mixed-build safety',
        '### Protocol version coordination',
        '## Schema fencing and migration safety',
        '## Routing safety: drain, block, and fail-closed',
        '## Coordination health: metrics, checks, and visibility',
        '### Frozen metric keys',
        '### Frozen health check names',
        '### Queue visibility',
        '## Stuck detectors and the repair path',
        '## Waterline observability surfaces',
        '## Config surface for rollout safety',
        '## Migration path',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Admission check',
        'Fail closed',
        'Mixed-build state',
        'Drain',
        'Block',
        'Routing health',
        'Coordination health',
        'Stuck',
        'Duplicate-risk indicator',
        'Rollout-safety envelope',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'BackendCapabilities',
        'LongPollCacheValidator',
        'WorkflowModeGuard',
        'ReadinessContract',
        'WaterlineEngineSource',
        'OperatorMetrics',
        'OperatorQueueVisibility',
        'HealthCheck',
        'WorkerCompatibilityFleet',
        'WorkerCompatibility',
        'TaskCompatibility',
        'WorkflowDefinitionFingerprint',
        'StandaloneWorkerVisibility',
        'ActivityTaskClaimer',
        'ActivityWorkerBridgeReason',
        'DefaultActivityTaskBridge',
        'DefaultWorkflowTaskBridge',
        'TaskRepair',
        'TaskRepairPolicy',
        'TaskRepairCandidates',
        'TaskWatchdog',
        'RunSummaryProjector',
        'StructuralLimits',
        'WorkerProtocolVersionResolver',
        'ControlPlaneVersionResolver',
    ];

    private const REQUIRED_HTTP_ROUTES = [
        '/api/health',
        '/api/ready',
        '/api/system/metrics',
        '/api/system/repair/pass',
        '/api/system/activity-timeouts/pass',
        '/api/system/retention/pass',
    ];

    private const REQUIRED_CONFIG_VARIABLES = [
        'DW_V2_NAMESPACE',
        'DW_V2_CURRENT_COMPATIBILITY',
        'DW_V2_SUPPORTED_COMPATIBILITIES',
        'DW_V2_COMPATIBILITY_NAMESPACE',
        'DW_V2_COMPATIBILITY_HEARTBEAT_TTL',
        'DW_V2_PIN_TO_RECORDED_FINGERPRINT',
        'DW_V2_GUARDRAILS_BOOT',
        'DW_V2_MULTI_NODE',
        'DW_V2_VALIDATE_CACHE_BACKEND',
        'DW_V2_CACHE_VALIDATION_MODE',
        'DW_V2_FLEET_VALIDATION_MODE',
        'DW_V2_TASK_DISPATCH_MODE',
        'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
        'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
        'DW_V2_TASK_REPAIR_SCAN_LIMIT',
        'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
    ];

    private const REQUIRED_VALIDATION_MODES = ['`silent`', '`warn`', '`throw`', '`fail`'];

    private const REQUIRED_FROZEN_METRIC_KEYS = [
        'repair_needed',
        'claim_failed',
        'compatibility_blocked',
        'ready_due',
        'dispatch_failed',
        'dispatch_overdue',
        'lease_expired',
        'oldest_lease_expired_at',
        'max_lease_expired_age_ms',
        'oldest_ready_due_at',
        'max_ready_due_age_ms',
        'unhealthy',
        'runnable_tasks',
        'delayed_tasks',
        'leased_tasks',
        'unhealthy_tasks',
        'repair_needed_runs',
        'claim_failed_runs',
        'compatibility_blocked_runs',
        'oldest_compatibility_blocked_started_at',
        'max_compatibility_blocked_age_ms',
        'missing_task_candidates',
        'selected_missing_task_candidates',
        'oldest_missing_run_started_at',
        'max_missing_run_age_ms',
        'required_compatibility',
        'active_workers',
        'active_worker_scopes',
        'active_workers_supporting_required',
        'fleet',
        'oldest_overdue_at',
        'max_overdue_ms',
        'fires_total',
        'failures_total',
        'redispatch_after_seconds',
        'loop_throttle_seconds',
        'scan_limit',
        'failure_backoff_max_seconds',
    ];

    private const REQUIRED_HEALTH_CHECK_NAMES = [
        'backend_capabilities',
        'run_summary_projection',
        'selected_run_projections',
        'history_retention_invariant',
        'command_contract_snapshots',
        'task_transport',
        'durable_resume_paths',
        'worker_compatibility',
        'scheduler_role',
    ];

    private const REQUIRED_WATERLINE_SURFACES = [
        'resources/js/screens/dashboard.vue',
        'resources/js/screens/workers.vue',
        'resources/js/screens/flows/index.vue',
        'resources/js/components/WorkerHealth.vue',
        'resources/js/components/ScheduleView.vue',
    ];

    private const REQUIRED_MIGRATIONS = [
        '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations',
        '2026_04_22_000200_create_workflow_worker_build_id_rollouts_table',
    ];

    private const REQUIRED_CLAIM_REASON_CODES = [
        'compatibility_unsupported',
        'backend_unsupported',
        'compatibility_blocked',
    ];

    private const REQUIRED_MIGRATION_PATH_STEPS = [
        '**Audit admission wiring.**',
        '**Turn on validation in `warn` mode.**',
        '**Surface mixed-build state.**',
        '**Pin fingerprints.**',
        '**Move repair cadence under operator control.**',
        '**Tighten to fail mode.**',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Rollout safety contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentDefinesEveryNamedTerm(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Rollout safety contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentReferencesCanonicalSupportClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_REFERENCED_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf(
                    'Rollout safety contract must reference %s as the canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentNamesServerHttpSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HTTP_ROUTES as $route) {
            $this->assertStringContainsString(
                $route,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s HTTP route so the health/repair surface is explicit.',
                    $route
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryConfigVariable(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_VARIABLES as $variable) {
            $this->assertStringContainsString(
                $variable,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s config variable so operators can see the full safety surface.',
                    $variable
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryValidationMode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_VALIDATION_MODES as $mode) {
            $this->assertStringContainsString(
                $mode,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s validation mode so the fail-closed behavior is unambiguous.',
                    $mode
                ),
            );
        }
    }

    public function testContractDocumentFreezesMetricKeys(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FROZEN_METRIC_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf(
                    'Rollout safety contract must pin the %s metric key under OperatorMetrics::snapshot().',
                    $key
                ),
            );
        }
    }

    public function testContractDocumentFreezesSchedulerRoleHealthRow(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/\|\s*`schedules`\s*\|[^|]*`active`[^|]*`paused`[^|]*`missed`[^|]*`oldest_overdue_at`[^|]*`max_overdue_ms`[^|]*`fires_total`[^|]*`failures_total`/',
            $contents,
            'Rollout safety contract must pin the schedules metric row so scheduler-role health keys stay legible through OperatorMetrics::snapshot().',
        );
    }

    public function testContractDocumentFreezesCompatibilityBlockedAgeRow(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/\|\s*`backlog`\s*\|[^|]*`oldest_compatibility_blocked_started_at`[^|]*`max_compatibility_blocked_age_ms`/',
            $contents,
            'Rollout safety contract must pin the backlog compatibility-blocked age row so operators can read "how stale is the worst mixed-build block?" from OperatorMetrics::snapshot() without a bespoke scan.',
        );
    }

    public function testContractDocumentFreezesLeaseExpiredAgeRow(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/\|\s*`tasks`\s*\|[^|]*`oldest_lease_expired_at`[^|]*`max_lease_expired_age_ms`/',
            $contents,
            'Rollout safety contract must pin the tasks lease-expired age row so operators can read "how long has the worst leased task been expired without redelivery?" from OperatorMetrics::snapshot() without a bespoke scan.',
        );
    }

    public function testContractDocumentFreezesReadyDueAgeRow(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/\|\s*`tasks`\s*\|[^|]*`oldest_ready_due_at`[^|]*`max_ready_due_age_ms`/',
            $contents,
            'Rollout safety contract must pin the tasks ready-due age row so operators can read queue latency ("how long has the oldest actionable task been waiting to dispatch?") from OperatorMetrics::snapshot() without walking workflow_tasks.',
        );
    }

    public function testContractDocumentFreezesHealthCheckNames(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEALTH_CHECK_NAMES as $name) {
            $this->assertStringContainsString(
                $name,
                $contents,
                sprintf(
                    'Rollout safety contract must pin the %s health check name under HealthCheck::snapshot().',
                    $name
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryWaterlineSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_WATERLINE_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf(
                    'Rollout safety contract must name the Waterline surface %s so coordination health stays observable to humans.',
                    $surface
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryRolloutSafetyMigration(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            $this->assertStringContainsString(
                $migration,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s migration so schema fencing is explicit.',
                    $migration
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryClaimReasonCode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CLAIM_REASON_CODES as $code) {
            $this->assertStringContainsString(
                $code,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s claim reason code so routing blocks are diagnosable.',
                    $code
                ),
            );
        }
    }

    public function testContractDocumentEnumeratesMigrationPathSteps(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_MIGRATION_PATH_STEPS as $step) {
            $this->assertStringContainsString(
                $step,
                $contents,
                sprintf('Rollout safety contract must describe migration path step %s.', $step),
            );
        }
    }

    public function testContractDocumentStatesAdmissionCannotBeSilentlyDowngraded(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/error.{0,40}severity MUST NOT be\s+silently downgraded/i',
            $contents,
            'Rollout safety contract must forbid silent runtime downgrade of an error-severity admission check.',
        );
    }

    public function testContractDocumentStatesRoutingNeverLosesTasks(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Routing safety never silently escalates to task loss/i',
            $contents,
            'Rollout safety contract must state routing safety never silently escalates to task loss.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST remain ready/i',
            $contents,
            'Rollout safety contract must state a blocked task remains ready.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT fabricate a\s+claimer/i',
            $contents,
            'Rollout safety contract must forbid fabricating a claimer when no compatible worker exists.',
        );
    }

    public function testContractDocumentStatesStuckConditionsAreNeverSilent(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/A stuck condition is never silent/i',
            $contents,
            'Rollout safety contract must state a stuck condition is never silent.',
        );
        $this->assertMatchesRegularExpression(
            '/repair path never fabricates work/i',
            $contents,
            'Rollout safety contract must state the repair path never fabricates work.',
        );
    }

    public function testContractDocumentStatesWaterlineRendersEveryFrozenKey(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Every frozen[^.]*metric[^.]*rendered\s+somewhere in Waterline/i',
            $contents,
            'Rollout safety contract must require Waterline to render every frozen metric key.',
        );
        $this->assertMatchesRegularExpression(
            '/(never directly against the database|reads through the server[^.]*API[^.]*routes|OperatorObservabilityRepository binding)/i',
            $contents,
            'Rollout safety contract must require Waterline to read through API/binding, not directly against the database.',
        );
    }

    public function testContractDocumentStatesPhaseFivePreservedUnchanged(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Rollout safety MUST NOT reintroduce cache as\s+a correctness dependency/i',
            $contents,
            'Rollout safety contract must state rollout safety does not reintroduce cache as a correctness dependency.',
        );
    }

    public function testContractDocumentStatesFingerprintPinningRule(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/matching role\s+MUST refuse a claim whose worker fingerprint does not match/i',
            $contents,
            'Rollout safety contract must state the matching role refuses claims with mismatched workflow definition fingerprints when pinning is enabled.',
        );
    }

    public function testContractDocumentStatesMigrationsAreReversible(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Every v2 migration named here is reversible by the standard\s+Laravel `down\(\)` path/i',
            $contents,
            'Rollout safety contract must state every v2 migration named here is reversible via the standard Laravel down() path.',
        );
    }

    public function testContractDocumentStatesProtocolVersionEnforcement(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Neither is allowed to serve a request that advertises a\s+protocol version outside the single-step compatibility window/i',
            $contents,
            'Rollout safety contract must require the protocol version resolvers to refuse requests outside the Phase 2 single-step compatibility window.',
        );
    }

    public function testContractDocumentStatesReverseCompatibilityForEnvNames(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/legacy `WORKFLOW_V2_\*` names remain supported/i',
            $contents,
            'Rollout safety contract must preserve support for the legacy WORKFLOW_V2_* env var names via Env::dw().',
        );
    }

    public function testContractDocumentDefersSchedulerLeaderElectionExplicitly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Scheduler leader election across replicas[\s\S]{0,400}follow-on roadmap issue/i',
            $contents,
            'Rollout safety contract must defer scheduler leader election to a follow-on roadmap issue.',
        );
    }

    public function testContractDocumentBuildsOnPhasesOneThroughFive(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Rollout safety contract must cite the Phase 1 execution-guarantees contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/worker-compatibility.md',
            $contents,
            'Rollout safety contract must cite the Phase 2 worker-compatibility contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/task-matching.md',
            $contents,
            'Rollout safety contract must cite the Phase 3 task-matching contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/control-plane-split.md',
            $contents,
            'Rollout safety contract must cite the Phase 4 control-plane-split contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/scheduler-correctness.md',
            $contents,
            'Rollout safety contract must cite the Phase 5 scheduler-correctness contract as its foundation.',
        );
        foreach (['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5', 'Phase 6'] as $phase) {
            $this->assertStringContainsString(
                $phase,
                $contents,
                sprintf('Rollout safety contract must cite %s so the phase lineage is explicit.', $phase),
            );
        }
    }

    public function testContractDocumentNamesRolloutSafetyTablesExplicitly(): void
    {
        $contents = $this->documentContents();

        foreach (['workflow_definition_fingerprints', 'workflow_worker_build_id_rollouts'] as $tableHint) {
            $this->assertStringContainsString(
                $tableHint,
                $contents,
                sprintf(
                    'Rollout safety contract must name the %s durable surface so schema fencing is visible.',
                    $tableHint
                ),
            );
        }
    }

    public function testContractDocumentPinsPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/RolloutSafetyDocumentationTest.php',
            $contents,
            'Rollout safety contract must name its own pinning test so future changes know where the guardrails live.',
        );
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
