<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 business reporting and app read-model contract documented
 * in docs/architecture/business-reporting-read-models.md. The doc is the
 * reference used by product docs, Waterline diagnostics, CLI reasoning,
 * and workflow authors when deciding whether data belongs in technical
 * runtime state or application-owned business projections.
 */
final class BusinessReportingReadModelsDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/business-reporting-read-models.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Business Reporting and App Read-Model Contract',
        '## Scope',
        '## Terminology',
        '## Authority Boundary',
        '## Stable Projection References',
        '## Milestone-Based Read-Model Pattern',
        '## Search Attributes, Memos, and Business Keys',
        '## Waterline and Runtime Operations',
        '## Test Strategy Alignment',
        '## Changing This Contract',
    ];

    private const REQUIRED_TERMS = [
        'Technical workflow state',
        'Business state',
        'App read model',
        'Milestone',
        'Projection reference',
        'Waterline',
    ];

    private const REQUIRED_IDENTITY_SURFACES = [
        'Workflow::workflowId()',
        'WorkflowStub::workflowId()',
        'CommandResult::workflowId()',
        'Workflow::runId()',
        'WorkflowStub::runId()',
        'CommandResult::runId()',
        'workflow_instance_id',
        'workflow_run_id',
        'workflow_id',
        'run_id',
    ];

    private const REQUIRED_RUNTIME_SURFACES = [
        'OperatorMetrics::snapshot()',
        'OperatorQueueVisibility::forNamespace()',
        'RunDetailView',
        'HistoryTimeline',
        'HistoryExport',
        'RunWaitView',
        'RunLineageView',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Business reporting contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDefinesRuntimeAndBusinessAuthorityTerms(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Business reporting contract must define term %s.', $term),
            );
        }

        $this->assertStringContainsString(
            'Technical workflow state is not business state.',
            $contents,
            'Business reporting contract must state the core authority split.',
        );
        $this->assertStringContainsString(
            'Waterline is a technical runtime UI.',
            $contents,
            'Business reporting contract must classify Waterline as runtime operations tooling.',
        );
    }

    public function testContractNamesStableIdentitySurfacesForAppProjections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_IDENTITY_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Business reporting contract must name identity surface %s.', $surface),
            );
        }
    }

    public function testContractRequiresMilestoneBasedAppReadModels(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Applications should update business read models at meaningful workflow\s+milestones/i',
            $contents,
            'Business reporting contract must recommend milestone-based read-model updates.',
        );
        $this->assertStringContainsString(
            'Workflow code must not write external state directly',
            $contents,
            'Business reporting contract must preserve the execution-guarantees side-effect boundary.',
        );
    }

    public function testContractKeepsRuntimeOperationsOnOperatorSurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RUNTIME_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Business reporting contract must keep runtime operations on %s.', $surface),
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
