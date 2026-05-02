<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 worker-deployment contract documented in
 * docs/architecture/worker-deployment.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server deployment guidance, cloud orchestration, and
 * test coverage for deployment identity, the lifecycle state
 * machine, the long-lived workflow compatibility policy, the frozen
 * blockage reason codes, the promotion check matrix, and the
 * server-controlled rollout HTTP surface. Changes to any named
 * guarantee must update this test and the documented contract in
 * the same change so drift is reviewed deliberately.
 */
final class WorkerDeploymentDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/worker-deployment.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Worker Deployment Contract',
        '## Scope',
        '## Terminology',
        '## Deployment identity',
        '## Deployment lifecycle',
        '## Workflow compatibility policy',
        '## Blockage diagnoses',
        '### Frozen blockage reason codes',
        '### Promotion check matrix',
        '### Drain, resume, and rollback checks',
        '## Server-controlled rollout semantics',
        '## Connecting replay-safety and rollout-safety to promotion',
        '## Long-lived workflow compatibility decisions',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Deployment',
        'Lifecycle state',
        'Compatibility policy',
        'Promotion',
        'Drain',
        'Resume',
        'Rollback',
        'Blockage',
        'Affected scope',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'WorkerDeployment',
        'DeploymentBlockage',
        'DeploymentLifecyclePlan',
        'DeploymentLifecycleState',
        'WorkflowCompatibilityPolicy',
        'DeploymentBlockageReason',
        'WorkerCompatibilityFleet',
        'WorkflowModeGuard',
        'OperatorMetrics',
    ];

    private const REQUIRED_LIFECYCLE_STATES = [
        'Pending',
        'Active',
        'Promoted',
        'Draining',
        'Drained',
        'RolledBack',
    ];

    private const REQUIRED_BLOCKAGE_REASONS = [
        'no_compatible_workers',
        'fleet_is_draining',
        'fingerprint_mismatch',
        'replay_safety_failed',
        'missing_worker_heartbeat',
        'incompatible_policy',
        'unknown_deployment',
    ];

    private const REQUIRED_HTTP_ROUTES = [
        '/api/deployments',
        '/api/deployments/{name}',
        '/api/deployments/{name}/promote',
        '/api/deployments/{name}/drain',
        '/api/deployments/{name}/resume',
        '/api/deployments/{name}/rollback',
    ];

    private const REQUIRED_FOUNDATION_PHASES = [
        'Phase 1',
        'Phase 2',
        'Phase 3',
        'Phase 4',
        'Phase 5',
        'Phase 6',
        'Phase 7',
    ];

    private const REQUIRED_FOUNDATION_DOCS = [
        'docs/architecture/execution-guarantees.md',
        'docs/architecture/worker-compatibility.md',
        'docs/architecture/task-matching.md',
        'docs/architecture/control-plane-split.md',
        'docs/architecture/scheduler-correctness.md',
        'docs/architecture/rollout-safety.md',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Worker deployment contract is missing heading %s.', $heading),
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
                sprintf('Worker deployment contract must define term %s in the Terminology section.', $term),
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
                    'Worker deployment contract must reference %s as the canonical implementation surface.',
                    $class,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryLifecycleState(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_LIFECYCLE_STATES as $state) {
            $this->assertStringContainsString(
                $state,
                $contents,
                sprintf(
                    'Worker deployment contract must name lifecycle state %s so the state machine is enumerated.',
                    $state,
                ),
            );
        }
    }

    public function testContractDocumentFreezesBlockageReasonCodes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_BLOCKAGE_REASONS as $reason) {
            $this->assertStringContainsString(
                sprintf('`%s`', $reason),
                $contents,
                sprintf(
                    'Worker deployment contract must pin the %s blockage reason code so the diagnosis surface stays machine-readable.',
                    $reason,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryHttpRoute(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HTTP_ROUTES as $route) {
            $this->assertStringContainsString(
                $route,
                $contents,
                sprintf(
                    'Worker deployment contract must name the %s HTTP route so the deployment lifecycle surface is explicit.',
                    $route,
                ),
            );
        }
    }

    public function testContractDocumentDeclaresPolicyDefaultPinned(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/`Pinned`[^\n]*default/i',
            $contents,
            'Worker deployment contract must declare Pinned as the default compatibility policy so unmigrated rollout rows cannot silently opt into auto-upgrade.',
        );
    }

    public function testContractDocumentStatesPromotionRefusalShape(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/409[^\n]*DeploymentBlockage/i',
            $contents,
            'Worker deployment contract must pin the 409 + DeploymentBlockage::toArray() refusal shape so the HTTP refusal stays stable.',
        );
    }

    public function testContractDocumentStatesTerminalStatesAreNotPromotable(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/terminal[\s\S]{0,200}cannot be promoted/i',
            $contents,
            'Worker deployment contract must state that terminal deployments cannot be promoted or resumed.',
        );
    }

    public function testContractDocumentStatesEveryBlockageIsReturnedNotJustTheFirst(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/returns \*\*every\*\* applicable blockage/i',
            $contents,
            'Worker deployment contract must require the planner to return every applicable blockage so operators see the full diagnosis in one round trip.',
        );
    }

    public function testContractDocumentStatesPolicyChangeRequiresNewDeployment(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/recreate the deployment/i',
            $contents,
            'Worker deployment contract must require operators to recreate the deployment to change the compatibility policy so audit history stays honest.',
        );
    }

    public function testContractDocumentBuildsOnPriorPhases(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FOUNDATION_PHASES as $phase) {
            $this->assertStringContainsString(
                $phase,
                $contents,
                sprintf('Worker deployment contract must cite %s so the phase lineage is explicit.', $phase),
            );
        }

        foreach (self::REQUIRED_FOUNDATION_DOCS as $doc) {
            $this->assertStringContainsString(
                $doc,
                $contents,
                sprintf('Worker deployment contract must cite the %s contract as part of its foundation.', $doc),
            );
        }
    }

    public function testContractDocumentPinsItsOwnPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/WorkerDeploymentDocumentationTest.php',
            $contents,
            'Worker deployment contract must name its own pinning test so future changes know where the guardrails live.',
        );
    }

    public function testContractDocumentDeclaresLegacyRouteCompatibility(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/legacy `POST \/api\/task-queues[^`]*build-ids\/drain`[\s\S]{0,200}continue to work unchanged/i',
            $contents,
            'Worker deployment contract must declare that the legacy build-id drain/resume routes continue to work unchanged so existing operators are not broken by the new deployment surface.',
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
