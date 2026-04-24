<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 operational liveness and transport repair contract
 * documented in docs/architecture/operational-liveness.md. The doc
 * is the single reference used by product docs, CLI reasoning,
 * Waterline diagnostics, server deployment guidance, cloud capacity
 * planning, and test coverage for bootstrap, transport-job shape,
 * lease management, redelivery vs repair, repair cadence, heartbeat
 * renewal, durable-next-resume source, worker-loss recovery, sweeper
 * scope, compatibility preservation, ingress serialization, and the
 * frozen metric and health check surface that makes stuck conditions
 * observable. Changes to any named guarantee must update this test
 * and the documented contract in the same change so drift is reviewed
 * deliberately.
 */
final class OperationalLivenessDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/operational-liveness.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Operational Liveness and Transport Repair Contract',
        '## Scope',
        '## Terminology',
        '## Bootstrap: the start command owns the first task',
        '## Transport jobs carry durable ids only',
        '## Lease management',
        '## Redelivery vs repair are two distinct flows',
        '## Repair cadence and bounded backoff',
        '## Heartbeats renew the owning lease',
        '## Every non-terminal run has a durable next-resume source',
        '## Worker-loss recovery is lease-driven',
        '## Sweeper scope is thin transport repair only',
        '## Repair preserves compatibility markers',
        '## Ingress serialization',
        '## Stuck-state observability',
        '## Admin HTTP surface for liveness',
        '## Config surface for operational liveness',
        '## Migration path',
        '## Tables and migrations',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Bootstrap',
        'Transport job',
        'Lease',
        'Lease expiry',
        'Redelivery',
        'Repair',
        'Repair cadence',
        'Repair attention flag',
        'Durable resume source',
        'Ingress',
        'Run-level lease',
        'Append domain',
        'Sweeper',
        'Compatibility markers',
        'Heartbeat renewal',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'TaskRepair',
        'TaskRepairPolicy',
        'TaskRepairCandidates',
        'ActivityTaskClaimer',
        'ActivityLease',
        'TaskDispatcher',
        'RunWorkflowTask',
        'RunActivityTask',
        'RunTimerTask',
        'RunSummaryProjector',
        'OperatorMetrics',
        'HealthCheck',
        'OperatorQueueVisibility',
        'HeartbeatProgress',
        'RepairBlockedReason',
        'WorkflowExecutor',
        'ActivityOutcomeRecorder',
        'DefaultWorkflowTaskBridge',
        'ReadinessContract',
        'WorkflowDefinitionFingerprint',
        'TaskCompatibility',
    ];

    private const REQUIRED_HTTP_ROUTES = ['/api/system/repair/pass', '/api/system/activity-timeouts/pass'];

    private const REQUIRED_CONFIG_VARIABLES = [
        'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
        'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
        'DW_V2_TASK_REPAIR_SCAN_LIMIT',
        'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
        'DW_V2_TASK_DISPATCH_MODE',
        'DW_V2_PIN_TO_RECORDED_FINGERPRINT',
    ];

    private const REQUIRED_POLICY_KNOBS = [
        'redispatch_after_seconds',
        'loop_throttle_seconds',
        'scan_limit',
        'failure_backoff_max_seconds',
        'SCAN_STRATEGY',
        'FAILURE_BACKOFF_STRATEGY',
        'scope_fair_round_robin',
        'exponential_by_repair_count',
    ];

    private const REQUIRED_CLAIM_REASON_CODES = [
        'task_not_found',
        'task_not_activity',
        'task_not_ready',
        'task_not_due',
        'activity_execution_missing',
        'activity_execution_not_found',
        'workflow_run_missing',
        'backend_unsupported',
        'compatibility_unsupported',
    ];

    private const REQUIRED_REPAIR_BLOCKED_REASONS = [
        'unsupported_history',
        'waiting_for_compatible_worker',
        'selected_run_not_current',
        'run_closed',
        'repair_not_needed',
    ];

    private const REQUIRED_LIVENESS_STATES = [
        'closed',
        'repair_needed',
        'workflow_replay_blocked',
        'activity_running_without_task',
        'waiting_for_condition',
        'waiting_for_signal',
        'waiting_for_child',
        'activity_task_waiting_for_compatible_worker',
        'activity_task_claim_failed',
        'activity_task_leased',
        'activity_task_ready',
        'workflow_task_waiting_for_compatible_worker',
        'workflow_task_claim_failed',
        'workflow_task_leased',
        'workflow_task_ready',
        'timer_task_leased',
        'timer_scheduled',
    ];

    private const REQUIRED_FROZEN_METRIC_KEYS = [
        'runs.repair_needed',
        'runs.claim_failed',
        'runs.compatibility_blocked',
        'tasks.ready',
        'tasks.ready_due',
        'tasks.delayed',
        'tasks.leased',
        'tasks.dispatch_failed',
        'tasks.claim_failed',
        'tasks.dispatch_overdue',
        'tasks.lease_expired',
        'tasks.unhealthy',
        'backlog.repair_needed_runs',
        'backlog.claim_failed_runs',
        'backlog.compatibility_blocked_runs',
        'repair.missing_task_candidates',
        'repair.selected_missing_task_candidates',
        'repair.oldest_missing_run_started_at',
        'repair.max_missing_run_age_ms',
        'workers.required_compatibility',
        'workers.active_workers',
        'workers.active_workers_supporting_required',
        'repair_policy.redispatch_after_seconds',
        'repair_policy.loop_throttle_seconds',
        'repair_policy.scan_limit',
        'repair_policy.failure_backoff_max_seconds',
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
        'long_poll_wake_acceleration',
    ];

    private const REQUIRED_MIGRATIONS = [
        '2026_04_05_000103_create_workflow_tasks_table',
        '2026_04_05_000106_create_workflow_run_summaries_table',
        '2026_04_08_000124_create_activity_attempts_table',
        '2026_04_08_000126_create_worker_compatibility_heartbeats_table',
    ];

    private const REQUIRED_MIGRATION_PATH_STEPS = [
        '**Audit transport delivery.**',
        '**Turn on projection reads.**',
        '**Enable repair metrics and checks.**',
        '**Pin `DW_V2_TASK_REPAIR_*` values.**',
        '**Dry-run the admin repair routes.**',
        '**Decommission the optional sweeper if present.**',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Operational liveness contract is missing heading %s.', $heading),
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
                sprintf('Operational liveness contract must define term %s in the Terminology section.', $term),
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
                    'Operational liveness contract must reference %s as the canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentNamesAdminHttpSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HTTP_ROUTES as $route) {
            $this->assertStringContainsString(
                $route,
                $contents,
                sprintf(
                    'Operational liveness contract must name the %s HTTP route so the repair surface is explicit.',
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
                    'Operational liveness contract must name the %s config variable so operators can see the full surface.',
                    $variable
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryRepairPolicyKnob(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_POLICY_KNOBS as $knob) {
            $this->assertStringContainsString(
                $knob,
                $contents,
                sprintf(
                    'Operational liveness contract must name the %s repair policy knob so repair cadence is bounded and reviewable.',
                    $knob
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
                    'Operational liveness contract must name the %s claim reason code so claim decisions are diagnosable.',
                    $code
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryRepairBlockedReason(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_REPAIR_BLOCKED_REASONS as $reason) {
            $this->assertStringContainsString(
                $reason,
                $contents,
                sprintf(
                    'Operational liveness contract must name the %s repair blocked reason so a blocked repair is diagnosable.',
                    $reason
                ),
            );
        }
    }

    public function testContractDocumentEnumeratesLivenessStateValues(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_LIVENESS_STATES as $state) {
            $this->assertStringContainsString(
                $state,
                $contents,
                sprintf(
                    'Operational liveness contract must enumerate the %s liveness_state value so the durable resume source is explicit.',
                    $state
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
                    'Operational liveness contract must pin the %s metric key under OperatorMetrics::snapshot().',
                    $key
                ),
            );
        }
    }

    public function testContractDocumentFreezesHealthCheckNames(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEALTH_CHECK_NAMES as $name) {
            $this->assertStringContainsString(
                $name,
                $contents,
                sprintf(
                    'Operational liveness contract must pin the %s health check name under HealthCheck::snapshot().',
                    $name
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryMigration(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            $this->assertStringContainsString(
                $migration,
                $contents,
                sprintf(
                    'Operational liveness contract must name the %s migration so schema fencing is explicit.',
                    $migration
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
                sprintf('Operational liveness contract must describe migration path step %s.', $step),
            );
        }
    }

    public function testContractDocumentStatesStartCommandIsTransactional(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/writes the `workflow_instances`, `workflow_runs`,\s+and `workflow_tasks` rows atomically under the same database\s+transaction/i',
            $contents,
            'Operational liveness contract must require the start command to create instance, run, and first task in a single transaction.',
        );
        $this->assertMatchesRegularExpression(
            '/DB::afterCommit\(\)/i',
            $contents,
            'Operational liveness contract must state the first transport job is dispatched via DB::afterCommit() so no pre-commit delivery is possible.',
        );
    }

    public function testContractDocumentStatesTransportJobsCarryOnlyTaskId(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/constructor accepts a single `task_id` string\s+argument and nothing else/i',
            $contents,
            'Operational liveness contract must require every transport job constructor to accept only a task_id.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT carry the task row itself, the run\s+summary, the workflow snapshot/i',
            $contents,
            'Operational liveness contract must forbid carrying workflow state in transport job payloads.',
        );
    }

    public function testContractDocumentSeparatesRedeliveryAndRepair(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/redelivery MUST NOT write a new task row/i',
            $contents,
            'Operational liveness contract must state redelivery reuses the existing task row.',
        );
        $this->assertMatchesRegularExpression(
            '/repair MUST be safe against concurrent repair passes/i',
            $contents,
            'Operational liveness contract must require repair to be concurrency-safe.',
        );
        $this->assertMatchesRegularExpression(
            '/Repair never fabricates history/i',
            $contents,
            'Operational liveness contract must state repair never fabricates history rows.',
        );
    }

    public function testContractDocumentStatesHeartbeatRenewsOwningLease(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/A heartbeat never creates a new `activity_attempts` row/i',
            $contents,
            'Operational liveness contract must state a heartbeat never creates a new activity attempt row.',
        );
        $this->assertMatchesRegularExpression(
            '/heartbeat from a worker that does not own the lease is rejected/i',
            $contents,
            'Operational liveness contract must require heartbeat rejection from a non-owning worker.',
        );
    }

    public function testContractDocumentStatesEveryNonTerminalRunHasDurableResumeSource(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Every non-terminal run MUST project exactly one `liveness_state`\s+and at most one `next_task_id`/i',
            $contents,
            'Operational liveness contract must require every non-terminal run to project exactly one liveness_state and at most one next_task_id.',
        );
        $this->assertMatchesRegularExpression(
            '/`resume_source_kind` and `resume_source_id` MUST be consistent\s+with `liveness_state`/i',
            $contents,
            'Operational liveness contract must require resume_source_kind/id to be consistent with liveness_state.',
        );
    }

    public function testContractDocumentStatesWorkerLossDoesNotMutateRunStatus(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/resolve through lease\s+expiry and reassignment/i',
            $contents,
            'Operational liveness contract must require worker-loss recovery through lease expiry.',
        );
        $this->assertMatchesRegularExpression(
            '/It MUST\s+NOT mutate run status/i',
            $contents,
            'Operational liveness contract must forbid worker-loss recovery from mutating run status.',
        );
    }

    public function testContractDocumentBoundsSweeperScope(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/sweeper MUST NOT run the workflow authoring layer/i',
            $contents,
            'Operational liveness contract must forbid a sweeper from running the workflow authoring layer.',
        );
        $this->assertMatchesRegularExpression(
            '/deployment that runs no sweeper at all is still correct/i',
            $contents,
            'Operational liveness contract must state a deployment with no sweeper is still correct.',
        );
    }

    public function testContractDocumentStatesRepairPreservesCompatibilityMarkers(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/A repair-driven redispatch MUST preserve the Phase 2 compatibility\s+markers/i',
            $contents,
            'Operational liveness contract must require repair to preserve Phase 2 compatibility markers.',
        );
        $this->assertMatchesRegularExpression(
            '/redispatched task MUST NOT suddenly become eligible for a\s+different compatibility scope/i',
            $contents,
            'Operational liveness contract must forbid widening a redispatched task to a different compatibility scope.',
        );
    }

    public function testContractDocumentStatesIngressSerializesThroughRunLevelLease(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Every run-mutating ingress serialises through the same run-level\s+lease or the append domain/i',
            $contents,
            'Operational liveness contract must require every run-mutating ingress to serialise through the run-level lease or append domain.',
        );
        $this->assertMatchesRegularExpression(
            '/A bulk operation that touches multiple runs MUST take each\s+run-level lock in a stable order/i',
            $contents,
            'Operational liveness contract must require a stable lock order for bulk cross-run operations.',
        );
    }

    public function testContractDocumentStatesStuckIsNeverSilent(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/A stuck condition is never silent/i',
            $contents,
            'Operational liveness contract must state stuck conditions are never silent.',
        );
    }

    public function testContractDocumentStatesActivityLeaseDurationIsFrozen(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/ActivityLease::DURATION_MINUTES.{0,40}frozen at 5/i',
            $contents,
            'Operational liveness contract must pin ActivityLease::DURATION_MINUTES at 5 minutes.',
        );
    }

    public function testContractDocumentStatesRepairScanStrategyBoundsFairness(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/`SCAN_STRATEGY = scope_fair_round_robin` guarantees that a repair\s+pass does not starve one namespace, instance, or run scope/i',
            $contents,
            'Operational liveness contract must name scope_fair_round_robin as the fairness strategy.',
        );
    }

    public function testContractDocumentStatesRepairPolicyExposesSnapshot(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/TaskRepairPolicy::snapshot\(\)`?\s+exposes the full cadence/i',
            $contents,
            'Operational liveness contract must require TaskRepairPolicy::snapshot() to expose the full cadence.',
        );
    }

    public function testContractDocumentStatesMigrationsAreReversible(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Every migration named here is reversible by the standard Laravel\s+`down\(\)` path/i',
            $contents,
            'Operational liveness contract must state every migration named is reversible via the standard Laravel down() path.',
        );
    }

    public function testContractDocumentPreservesLegacyEnvVarNames(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/legacy `WORKFLOW_V2_TASK_REPAIR_\*` names remain supported via\s+`Env::dw\(\)`/i',
            $contents,
            'Operational liveness contract must preserve legacy WORKFLOW_V2_TASK_REPAIR_* env names via Env::dw().',
        );
    }

    public function testContractDocumentDefersSchedulerLeaderElectionExplicitly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Scheduler leader election across replicas[\s\S]{0,400}follow-on roadmap issue/i',
            $contents,
            'Operational liveness contract must defer scheduler leader election to a follow-on roadmap issue.',
        );
    }

    public function testContractDocumentStatesPhaseFivePreservedUnchanged(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/never reintroduces cache as a correctness dependency/i',
            $contents,
            'Operational liveness contract must state operational liveness never reintroduces cache as a correctness dependency.',
        );
    }

    public function testContractDocumentBuildsOnPhasesOneThroughSix(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Operational liveness contract must cite the Phase 1 execution-guarantees contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/worker-compatibility.md',
            $contents,
            'Operational liveness contract must cite the Phase 2 worker-compatibility contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/task-matching.md',
            $contents,
            'Operational liveness contract must cite the Phase 3 task-matching contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/control-plane-split.md',
            $contents,
            'Operational liveness contract must cite the Phase 4 control-plane-split contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/scheduler-correctness.md',
            $contents,
            'Operational liveness contract must cite the Phase 5 scheduler-correctness contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/rollout-safety.md',
            $contents,
            'Operational liveness contract must cite the Phase 6 rollout-safety contract as its foundation.',
        );
        foreach (['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5', 'Phase 6'] as $phase) {
            $this->assertStringContainsString(
                $phase,
                $contents,
                sprintf('Operational liveness contract must cite %s so the phase lineage is explicit.', $phase),
            );
        }
    }

    public function testContractDocumentPinsPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/OperationalLivenessDocumentationTest.php',
            $contents,
            'Operational liveness contract must name its own pinning test so future changes know where the guardrails live.',
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
