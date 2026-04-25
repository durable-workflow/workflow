<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 task-matching and dispatch contract documented in
 * docs/architecture/task-matching.md. The doc is the single reference
 * used by product docs, CLI reasoning, Waterline diagnostics, server
 * deployment guidance, and test coverage for ready-task discovery,
 * claim/lease ownership, dispatch publication, wake notification,
 * partition primitives, and lease-based backpressure. Changes to any
 * named guarantee must update this test and the documented contract in
 * the same change so drift is reviewed deliberately.
 */
final class TaskMatchingDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/task-matching.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Task Matching and Dispatch Contract',
        '## Scope',
        '## Terminology',
        '## The matching role',
        '## Ready-task discovery',
        '## Claim and lease ownership',
        '### Workflow task claim outcomes',
        '### Activity task claim outcomes',
        '### Worker-facing reason translation',
        '### Lease expiry and redelivery',
        '## Dispatch and enqueue',
        '## Wake notification',
        '## Queue partitioning and routing primitives',
        '## Backpressure and fairness',
        '## Operator-visible matching state',
        '## Coupling boundaries with durable history',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Matching role',
        'Ready task',
        'Eligible task set',
        'Claim',
        'Lease',
        'Redelivery',
        'Wake channel',
        'Partition primitive',
        'Backpressure',
    ];

    private const REQUIRED_PARTITION_PRIMITIVES = [
        '**`connection`**',
        '**`queue`**',
        '**`compatibility`**',
        '**`namespace`**',
    ];

    private const REQUIRED_WORKFLOW_CLAIM_REASONS = [
        'task_not_found',
        'task_not_workflow',
        'task_not_claimable',
        'run_not_found',
        'run_closed',
        'backend_unavailable',
        'compatibility_blocked',
    ];

    private const REQUIRED_ACTIVITY_CLAIM_REASONS = [
        'task_not_activity',
        'task_not_ready',
        'task_not_due',
        'activity_execution_missing',
        'workflow_run_missing',
        'backend_unsupported',
        'compatibility_unsupported',
    ];

    private const REQUIRED_CONFIG_KEYS = [
        'workflows.v2.task_dispatch_mode',
        'DW_V2_TASK_DISPATCH_MODE',
        'workflows.v2.compatibility.namespace',
        'workflows.v2.matching_role.queue_wake_enabled',
        'DW_V2_MATCHING_ROLE_QUEUE_WAKE',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'DefaultWorkflowTaskBridge',
        'DefaultActivityTaskBridge',
        'ActivityTaskClaimer',
        'ActivityWorkerBridgeReason',
        'TaskDispatcher',
        'TaskBackendCapabilities',
        'TaskCompatibility',
        'TaskRepair',
        'LongPollWakeStore',
        'CacheLongPollWakeStore',
        'OperatorMetrics',
        'OperatorQueueVisibility',
        'RunSummaryProjector',
        'LifecycleEventDispatcher',
        'ActivityLease',
    ];

    private const REQUIRED_DISPATCH_JOBS = ['RunWorkflowTask', 'RunActivityTask', 'RunTimerTask'];

    private const REQUIRED_DEPLOYMENT_SHAPES = [
        '**In-worker library shape**',
        '**In-server HTTP shape**',
        '**Dedicated matching role shape**',
    ];

    private const REQUIRED_HTTP_ROUTES = [
        '/api/worker/workflow-tasks/poll',
        '/api/worker/activity-tasks/poll',
        '/api/system/metrics',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Task matching contract is missing heading %s.', $heading),
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
                sprintf('Task matching contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentNamesEveryPartitionPrimitive(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PARTITION_PRIMITIVES as $primitive) {
            $this->assertStringContainsString(
                $primitive,
                $contents,
                sprintf('Task matching contract must name the %s partition primitive.', $primitive),
            );
        }
    }

    public function testContractDocumentNamesEveryWorkflowClaimReasonCode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_WORKFLOW_CLAIM_REASONS as $reason) {
            $this->assertStringContainsString(
                $reason,
                $contents,
                sprintf('Task matching contract must name the %s workflow claim reason code.', $reason),
            );
        }
    }

    public function testContractDocumentNamesEveryActivityClaimReasonCode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ACTIVITY_CLAIM_REASONS as $reason) {
            $this->assertStringContainsString(
                $reason,
                $contents,
                sprintf('Task matching contract must name the %s activity claim reason code.', $reason),
            );
        }
    }

    public function testContractDocumentNamesDispatchModeConfigSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf('Task matching contract must name the %s config/env key.', $key),
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
                    'Task matching contract must reference %s as the canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentNamesDispatchJobClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DISPATCH_JOBS as $job) {
            $this->assertStringContainsString(
                $job,
                $contents,
                sprintf('Task matching contract must name the %s dispatch job.', $job),
            );
        }
    }

    public function testContractDocumentDescribesEveryDeploymentShape(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DEPLOYMENT_SHAPES as $shape) {
            $this->assertStringContainsString(
                $shape,
                $contents,
                sprintf('Task matching contract must describe the %s deployment shape.', $shape),
            );
        }
    }

    public function testContractDocumentNamesWorkerHttpSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HTTP_ROUTES as $route) {
            $this->assertStringContainsString(
                $route,
                $contents,
                sprintf('Task matching contract must name the %s HTTP route.', $route),
            );
        }
    }

    public function testContractDocumentNamesAvailabilityCeilingTolerance(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/one-second availability ceiling/i',
            $contents,
            'Task matching contract must name the one-second availability ceiling so backends with sub-second timestamp drift are covered.',
        );
        $this->assertStringContainsString(
            'SQLite',
            $contents,
            'Task matching contract must call out the SQLite cross-backend tolerance reason.',
        );
    }

    public function testContractDocumentNamesPollBatchCap(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/maximum batch of 100 rows/i',
            $contents,
            'Task matching contract must name the 100-row poll batch cap.',
        );
    }

    public function testContractDocumentNamesWakeSnapshotChangedSemantics(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            '`snapshot()`',
            $contents,
            'Task matching contract must name the snapshot() wake primitive.',
        );
        $this->assertStringContainsString(
            '`changed()`',
            $contents,
            'Task matching contract must name the changed() wake primitive.',
        );
        $this->assertMatchesRegularExpression(
            '/60-second signal TTL/i',
            $contents,
            'Task matching contract must state the 60-second wake signal TTL upper bound.',
        );
    }

    public function testContractDocumentStatesWakeIsNotTheCorrectnessBoundary(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/not the correctness boundary/i',
            $contents,
            'Task matching contract must state wake notification is a performance optimisation, not the correctness boundary.',
        );
    }

    public function testContractDocumentNamesLeaseDurations(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/5 minutes/i',
            $contents,
            'Task matching contract must name the 5-minute workflow task lease duration.',
        );
    }

    public function testContractDocumentDefersPhasesFourFiveAndSixExplicitly(): void
    {
        $contents = $this->documentContents();

        foreach (['Phase 4', 'Phase 5', 'Phase 6'] as $phase) {
            $this->assertStringContainsString(
                $phase,
                $contents,
                sprintf('Task matching contract must explicitly defer follow-on work to %s.', $phase),
            );
        }
    }

    public function testContractDocumentBuildsOnPhaseOneAndPhaseTwo(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Task matching contract must cite the Phase 1 execution-guarantees contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/worker-compatibility.md',
            $contents,
            'Task matching contract must cite the Phase 2 worker-compatibility contract as its foundation.',
        );
    }

    public function testContractDocumentStatesMultiNodeRequiresSharedWakeBackend(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Multi-node deployment requires a shared backend/i',
            $contents,
            'Task matching contract must state multi-node deployments require a shared wake backend.',
        );
    }

    public function testContractDocumentStatesLostRaceIsNormal(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/lost-race outcome/i',
            $contents,
            'Task matching contract must describe task_not_claimable as the normal lost-race outcome, not a bug.',
        );
    }

    public function testContractDocumentNamesAfterCommitDeferral(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'DB::afterCommit()',
            $contents,
            'Task matching contract must name the DB::afterCommit() deferral so rolled-back tasks are never published.',
        );
    }

    public function testContractDocumentNamesTheDedicatedMatchingRoleDaemonLoop(): void
    {
        $contents = $this->documentContents();

        foreach ([
            'workflow:v2:repair-pass --loop',
            '--sleep-seconds',
            'SIGTERM',
            'SIGINT',
            'TaskRepairPolicy::loopThrottleSeconds()',
        ] as $token) {
            $this->assertStringContainsString(
                $token,
                $contents,
                sprintf(
                    'Task matching contract must name %s so operators know how to deploy the dedicated matching-role daemon and how it coordinates with cooperating processes.',
                    $token,
                ),
            );
        }
    }

    public function testContractDocumentExposesMatchingRoleShapeOnOperatorSnapshot(): void
    {
        $contents = $this->documentContents();

        foreach (['`matching_role`', '`queue_wake_enabled`', '`shape`', '`task_dispatch_mode`'] as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf(
                    'Task matching contract must name the %s field on the operator metrics snapshot so the matching-role deployment shape is observable per node.',
                    $field,
                ),
            );
        }

        foreach (['`in_worker`', '`dedicated`'] as $shapeValue) {
            $this->assertStringContainsString(
                $shapeValue,
                $contents,
                sprintf(
                    'Task matching contract must name the %s matching-role shape value so operators can interpret the snapshot.',
                    $shapeValue,
                ),
            );
        }
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
