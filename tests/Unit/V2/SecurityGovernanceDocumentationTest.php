<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 security and governance direction contract documented in
 * docs/architecture/security-governance.md. The doc is the reference for
 * command identity, capability vocabulary, durable audit facts,
 * Waterline/host auth delegation, network posture, release posture,
 * Cloud identity boundaries, codec decode boundaries, and
 * cross-namespace service authorization.
 */
final class SecurityGovernanceDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/security-governance.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Security and Governance Direction Contract',
        '## Scope',
        '## Terminology',
        '## Command Contract Facts',
        '## Capability Vocabulary',
        '## Actor Identity Resolution',
        '## Authorization Boundaries',
        '## Durable Audit Data',
        '## Operator Auth Boundary',
        '## Network Posture',
        '### Webhook Ingress',
        '### Worker-To-Backend',
        '### Operator Surfaces',
        '### Secret Rotation',
        '## Release And Support Posture',
        '## Cloud Hosted Identity Boundary',
        '## Codec-Server Trust Boundary',
        '## Cross-Namespace Service Authorization',
        '## Documentation Alignment',
        '## Test Strategy Alignment',
        '## Changing This Contract',
    ];

    private const REQUIRED_TERMS = [
        'Actor identity',
        'Service identity',
        'Capability',
        'Target resource',
        'Auth outcome',
        'Request fingerprint',
        'Audit record',
        'Trust boundary',
        'Operator surface',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_CAPABILITIES = [
        'start workflow' => 'workflow.start',
        'signal workflow' => 'workflow.signal',
        'update workflow' => 'workflow.update',
        'repair workflow/task state' => 'workflow.repair',
        'cancel workflow' => 'workflow.cancel',
        'terminate workflow' => 'workflow.terminate',
        'archive workflow/history' => 'workflow.archive',
        'create schedule' => 'schedule.create',
        'pause schedule' => 'schedule.pause',
        'resume schedule' => 'schedule.resume',
        'update schedule' => 'schedule.update',
        'trigger schedule' => 'schedule.trigger',
        'backfill schedule' => 'schedule.backfill',
        'delete schedule' => 'schedule.delete',
    ];

    private const REQUIRED_COMMAND_FACTS = [
        'actor or service identity summary',
        'capability',
        'target resource',
        'auth outcome',
        'request fingerprint',
        'command outcome',
    ];

    private const REQUIRED_CONTEXT_FIELDS = [
        'CommandContext.context.caller',
        'CommandContext.context.principal',
        'CommandContext.context.request.fingerprint',
        'context.auth.status',
        'context.auth.method',
        'workflow_commands.context',
        'workflow_commands.target_scope',
        'requested_workflow_run_id',
        'resolved_workflow_run_id',
        'workflow_commands.status',
        'workflow_commands.outcome',
        'workflow_commands.rejection_reason',
    ];

    private const REQUIRED_AUDIT_SURFACES = [
        'workflow_commands',
        'workflow_history_events',
        'workflow_schedule_history_events',
        'workflow_service_calls',
        'workflow_signals',
        'workflow_updates',
        'HistoryEventPayloadContract',
        'HistoryTimeline',
        'HistoryExport',
    ];

    private const REQUIRED_WATERLINE_BOUNDARY_TERMS = [
        'host Laravel application',
        'web middleware',
        'auth guards',
        'CSRF middleware',
        'gates',
        'policies',
        'route middleware',
        'WATERLINE_NAMESPACE',
    ];

    private const REQUIRED_IDENTITY_EXTENSION_POINTS = [
        'SSO',
        'SAML',
        'SCIM',
        'LDAP',
        'OIDC',
        'service accounts',
        'customer identity providers',
    ];

    private const REQUIRED_NETWORK_POSTURE_TERMS = [
        'webhook ingress',
        'worker-to-backend',
        'operator surfaces',
        'mTLS',
        'private networking',
        'trusted proxy',
        'X-Forwarded-*',
        'secret rotation',
        'TLS',
    ];

    private const REQUIRED_RELEASE_POSTURE_TERMS = [
        'Data-handling posture',
        'Encryption posture',
        'Compliance posture',
        'Audit-log posture',
        'Support posture',
        'at-rest encryption',
        'SOC 2',
        'HIPAA',
        'PCI',
        'SIEM',
    ];

    private const REQUIRED_CLOUD_BOUNDARY_TERMS = [
        'organization',
        'project',
        'environment',
        'namespace',
        'runtime target',
        'Cloud principal',
        'Cloud audit logs',
    ];

    private const REQUIRED_CODEC_BOUNDARY_TERMS = [
        'codec server',
        'custom decoder',
        'plaintext application payloads',
        'customer-managed trust boundary',
        'redaction',
    ];

    private const REQUIRED_CROSS_NAMESPACE_POLICY_AXES = [
        'caller versus endpoint',
        'service policy',
        'operation policy',
        'rejected_forbidden',
        'rejected_not_found',
        'docs/architecture/cross-namespace-service-policy.md',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Security/governance contract is missing heading %s.', $heading),
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
                sprintf('Security/governance contract must define term %s.', $term),
            );
        }
    }

    public function testContractDocumentNamesEveryRequiredCommandFact(): void
    {
        $contents = strtolower($this->documentContents());

        foreach (self::REQUIRED_COMMAND_FACTS as $fact) {
            $this->assertStringContainsString(
                $fact,
                $contents,
                sprintf('Security/governance contract must name command fact %s.', $fact),
            );
        }
    }

    public function testContractDocumentMapsCommandPathsToExplicitCapabilities(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CAPABILITIES as $path => $capability) {
            $this->assertStringContainsString(
                sprintf('| %s | `%s` |', $path, $capability),
                $contents,
                sprintf('Security/governance contract must map %s to capability %s.', $path, $capability),
            );
        }
    }

    public function testContractDocumentNamesCommandContextAndOutcomeFields(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONTEXT_FIELDS as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf('Security/governance contract must name audit field %s.', $field),
            );
        }
    }

    public function testContractDocumentNamesDurableAuditSurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_AUDIT_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Security/governance contract must name durable audit surface %s.', $surface),
            );
        }
    }

    public function testContractDocumentPinsWaterlineHostAuthDelegation(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_WATERLINE_BOUNDARY_TERMS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must name Waterline host auth boundary term %s.', $term),
            );
        }

        $this->assertMatchesRegularExpression(
            '/Waterline[^.]+delegates\s+authentication\s+and\s+authorization\s+to\s+that\s+host\s+app/i',
            $contents,
            'Waterline auth delegation must be explicit.',
        );
    }

    public function testContractDocumentKeepsStrongerIdentitySystemsAsExtensionPoints(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_IDENTITY_EXTENSION_POINTS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must keep %s as a documented extension point.', $term),
            );
        }
    }

    public function testContractDocumentStatesNetworkPosture(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_NETWORK_POSTURE_TERMS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must state network posture term %s.', $term),
            );
        }
    }

    public function testContractDocumentStatesReleaseAndSupportPostureHonestly(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RELEASE_POSTURE_TERMS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must state release/support posture term %s.', $term),
            );
        }

        $this->assertMatchesRegularExpression(
            '/MUST NOT claim stronger security, encryption, compliance,\s+audit retention, support, or hosted identity guarantees/i',
            $contents,
            'Release docs must be forbidden from overclaiming posture.',
        );
    }

    public function testContractDocumentDefinesCloudHostedIdentityAboveNamespaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CLOUD_BOUNDARY_TERMS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must name Cloud boundary term %s.', $term),
            );
        }

        $this->assertMatchesRegularExpression(
            '/Cloud identity sits above namespaces/i',
            $contents,
            'Cloud identity boundary must be above namespaces.',
        );
    }

    public function testContractDocumentModelsCodecServerAsSeparateTrustBoundary(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CODEC_BOUNDARY_TERMS as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must name codec boundary term %s.', $term),
            );
        }
    }

    public function testContractDocumentCoversCrossNamespaceServicePolicyAxes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CROSS_NAMESPACE_POLICY_AXES as $term) {
            $this->assertStringContainsString(
                $term,
                $contents,
                sprintf('Security/governance contract must name cross-namespace policy axis %s.', $term),
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
