<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the docs replacement gate in
 * docs/architecture/activity-execution-model-replacement.md. The
 * current public Activity Execution Model page is a temporary stance
 * page; this test keeps the cleanup criteria visible until local
 * activities, worker sessions, and sticky execution have positive
 * runtime contracts and public docs.
 */
final class ActivityExecutionModelReplacementDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/activity-execution-model-replacement.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Activity Execution Model Replacement Contract',
        '## Scope',
        '## Replacement Primitives',
        '### Local Activities',
        '### Worker Sessions',
        '### Sticky Execution',
        '## Public Docs Gate',
        '## Discoverability Gate',
        '## Baseline Queued-Activity Page',
        '## Test Strategy Alignment',
        '## Changing This Contract',
    ];

    private const REQUIRED_PRIMITIVES = [
        'Local activities',
        'Worker sessions',
        'Sticky execution',
    ];

    private const REQUIRED_RUNTIME_TOPICS = [
        'execution semantics',
        'timeouts',
        'cancellation',
        'heartbeating',
        'failure detection',
        'routing',
    ];

    private const REQUIRED_PUBLIC_DOC_PATHS = [
        'docs/features/activity-execution-model.md',
        'docs/features/local-activities.md',
        'docs/features/worker-sessions.md',
        'docs/features/sticky-execution.md',
        'docs/defining-workflows/activities.md',
        'docs/defining-workflows/workflow-api.md',
        'docs/constraints/execution-guarantees.md',
    ];

    private const REQUIRED_DISCOVERABILITY_SURFACES = [
        'sidebars.js',
        'scripts/reference-docs-contract.json',
        'scripts/discoverability-contract.json',
        'scripts/check-llms-ai-surfaces.js',
        'llms-2.0.txt',
        'llms-full-2.0.txt',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Activity execution replacement contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentNamesEveryReplacementPrimitive(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PRIMITIVES as $primitive) {
            $this->assertStringContainsString(
                $primitive,
                $contents,
                sprintf('Replacement contract must name the %s primitive.', $primitive),
            );
        }
    }

    public function testContractDocumentRequiresRuntimeContractsBeforePositiveDocs(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RUNTIME_TOPICS as $topic) {
            $this->assertStringContainsString(
                $topic,
                $contents,
                sprintf('Replacement runtime contracts must cover %s.', $topic),
            );
        }

        $this->assertMatchesRegularExpression(
            '/not a runtime contract/i',
            $contents,
            'Replacement contract must state that the tracking document does not itself authorize the primitives.',
        );
    }

    public function testContractDocumentNamesPublicDocsThatMustBeUpdated(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PUBLIC_DOC_PATHS as $path) {
            $this->assertStringContainsString(
                $path,
                $contents,
                sprintf('Replacement contract must name public docs source %s.', $path),
            );
        }
    }

    public function testContractDocumentNamesDiscoverabilitySurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DISCOVERABILITY_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Replacement contract must name discoverability surface %s.', $surface),
            );
        }
    }

    public function testContractDocumentRequiresNegativePageRetirement(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/deleted or reduced to the ordinary queued-activity baseline/i',
            $contents,
            'Replacement contract must require deleting or reducing the temporary stance page.',
        );

        $this->assertMatchesRegularExpression(
            '/must not keep serving as a bucket/i',
            $contents,
            'Replacement contract must stop the Activity Execution Model page from remaining a disclaimer bucket.',
        );
    }

    public function testContractDocumentPinsItsOwnPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/ActivityExecutionModelReplacementDocumentationTest.php',
            $contents,
            'Replacement contract must name its own pinning test.',
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
