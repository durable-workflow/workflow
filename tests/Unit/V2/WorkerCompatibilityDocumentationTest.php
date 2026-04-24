<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 worker-compatibility and routing contract documented in
 * docs/architecture/worker-compatibility.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline diagnostics,
 * server deployment guidance, and test coverage for worker build
 * identity, compatibility markers, task/run inheritance, claim-time
 * enforcement, and rollout/rollback posture. Changes to any named
 * guarantee must update this test and the documented contract in the
 * same change so drift is reviewed deliberately.
 */
final class WorkerCompatibilityDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/worker-compatibility.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Worker Compatibility and Routing Contract',
        '## Scope',
        '## Terminology',
        '## Worker build identity',
        '## Compatibility markers',
        '## Compatibility inheritance',
        '## Routing and claim enforcement',
        '### Poll-time filtering',
        '### Claim-time enforcement',
        '### Dispatch-time routing',
        '## Operator-visible state',
        '## Rollout and rollback guidance',
        '## What this contract does not yet guarantee',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Worker build identity',
        'Compatibility marker',
        'Compatibility family',
        'Required marker',
        'Pinned run',
        'Fingerprint pinning',
    ];

    private const REQUIRED_IDENTITY_FIELDS = [
        '`worker_id`',
        '`host`',
        '`process_id`',
        '`namespace`',
        '`connection`',
        '`queue`',
        '`supported`',
        '`recorded_at`',
        '`expires_at`',
    ];

    private const REQUIRED_CONFIG_KEYS = [
        'workflows.v2.compatibility.current',
        'workflows.v2.compatibility.supported',
        'workflows.v2.compatibility.namespace',
        'workflows.v2.compatibility.heartbeat_ttl_seconds',
        'DW_V2_CURRENT_COMPATIBILITY',
        'DW_V2_SUPPORTED_COMPATIBILITIES',
        'DW_V2_COMPATIBILITY_NAMESPACE',
        'DW_V2_COMPATIBILITY_HEARTBEAT_TTL',
    ];

    private const REQUIRED_ENFORCEMENT_CODES = ['compatibility_blocked', 'compatibility_unsupported'];

    private const REQUIRED_REFERENCED_CLASSES = [
        'WorkerCompatibility',
        'WorkerCompatibilityFleet',
        'TaskCompatibility',
        'WorkflowDefinitionFingerprint',
        'DefaultWorkflowControlPlane',
        'DefaultWorkflowTaskBridge',
        'ActivityTaskClaimer',
        'TaskDispatcher',
        'WorkerProtocolVersion',
    ];

    private const REQUIRED_LIFECYCLE_TRANSITIONS = [
        '**Start**',
        '**Workflow tasks**',
        '**Activity tasks**',
        '**Retry runs**',
        '**Continue-as-new**',
        '**Child workflows**',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Worker compatibility contract is missing heading %s.', $heading),
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
                sprintf('Worker compatibility contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentNamesEveryWorkerIdentityField(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_IDENTITY_FIELDS as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf(
                    'Worker compatibility contract must name the %s field in the Worker build identity section.',
                    $field
                ),
            );
        }
    }

    public function testContractDocumentNamesConfigAndEnvSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf('Worker compatibility contract must name the %s config/env key.', $key),
            );
        }
    }

    public function testContractDocumentNamesClaimEnforcementReasonCodes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ENFORCEMENT_CODES as $code) {
            $this->assertStringContainsString(
                $code,
                $contents,
                sprintf('Worker compatibility contract must name the %s claim enforcement reason code.', $code),
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
                    'Worker compatibility contract must reference %s as the canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentDescribesLifecycleInheritance(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_LIFECYCLE_TRANSITIONS as $transition) {
            $this->assertStringContainsString(
                $transition,
                $contents,
                sprintf('Worker compatibility contract must describe inheritance for %s.', $transition),
            );
        }
    }

    public function testContractDocumentStatesAbsenceOfCompatibleWorkerIsAnExplicitState(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/absence of a compatible worker[^.]*explicit/i',
            $contents,
            'Worker compatibility contract must state the absence of a compatible worker is an explicit operational state.',
        );
        $this->assertMatchesRegularExpression(
            '/not[^.]*silently/i',
            $contents,
            'Worker compatibility contract must state tasks are not silently routed to incompatible workers.',
        );
    }

    public function testContractDocumentNamesHeartbeatTtlDefault(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/default\s*30/i',
            $contents,
            'Worker compatibility contract must name the default 30-second heartbeat TTL.',
        );
    }

    public function testContractDocumentNamesWildcardMarker(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/`\*`[^.]*accept any marker/i',
            $contents,
            'Worker compatibility contract must name the `*` wildcard marker and its "accept any marker" meaning.',
        );
        $this->assertMatchesRegularExpression(
            '/never stamped with\s+`\*`/i',
            $contents,
            'Worker compatibility contract must state runs are never stamped with the `*` wildcard.',
        );
    }

    public function testContractDocumentDefersPhaseThreeAndLaterExplicitly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/(dedicated task[- ]matching|task[- ]matching service)[\s\S]{0,200}Phase 3|Phase 3[\s\S]{0,200}(dedicated task[- ]matching|task[- ]matching service)/i',
            $contents,
            'Worker compatibility contract must explicitly defer dedicated task matching to Phase 3.',
        );
        $this->assertMatchesRegularExpression(
            '/(control[- ]plane[\/ ]data[- ]plane|control[- ]plane.{0,40}split)[\s\S]{0,200}Phase 4|Phase 4[\s\S]{0,200}(control[- ]plane[\/ ]data[- ]plane|control[- ]plane.{0,40}split)/i',
            $contents,
            'Worker compatibility contract must explicitly defer control-plane/data-plane split to Phase 4.',
        );
    }

    public function testContractDocumentBuildsOnExecutionGuarantees(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Worker compatibility contract must cite the Phase 1 execution-guarantees contract as its foundation.',
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
