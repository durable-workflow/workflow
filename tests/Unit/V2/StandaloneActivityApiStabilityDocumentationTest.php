<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;

final class StandaloneActivityApiStabilityDocumentationTest extends TestCase
{
    public function testStandaloneActivityServerFacingSurfacesAreDocumented(): void
    {
        $document = $this->fileContents('docs/api-stability.md');

        $this->assertStringContainsString('Workflow\\V2\\Support\\StandaloneActivityStartService', $document);
        $this->assertStringContainsString(
            'Workflow\\V2\\StandaloneActivity\\StandaloneActivityHostType',
            $document,
        );
        $this->assertStringContainsString('## Server-facing standalone activity stability list', $document);
        $this->assertStringContainsString('standalone-activity start contract', $document);
        $this->assertStringContainsString(StandaloneActivityHostType::WORKFLOW_TYPE, $document);
        $this->assertStringContainsString('stable wire identifier', $document);
        $this->assertMatchesRegularExpression(
            '/## Server-facing standalone activity stability list.*'
                . 'Workflow\\\\V2\\\\Support\\\\StandaloneActivityStartService.*'
                . 'Workflow\\\\V2\\\\StandaloneActivity\\\\StandaloneActivityHostType/s',
            $document,
        );
    }

    public function testStandaloneActivityServerFacingClassesCarryApiAnnotation(): void
    {
        foreach ([
            'src/V2/Support/StandaloneActivityStartService.php',
            'src/V2/StandaloneActivity/StandaloneActivityHostType.php',
        ] as $path) {
            $this->assertStringContainsString(
                '@api Stable class surface consumed by the standalone workflow-server.',
                $this->fileContents($path),
                sprintf('%s must carry an @api annotation when docs/api-stability.md publishes it.', $path),
            );
        }
    }

    public function testStandaloneActivityHostMarkerRecognizesPersistedHostIdentityColumns(): void
    {
        $this->assertTrue(StandaloneActivityHostType::isHostRun(new WorkflowRun([
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'workflow_class' => 'not-the-marker',
        ])));

        $this->assertTrue(StandaloneActivityHostType::isHostRun(new WorkflowRun([
            'workflow_type' => 'not-the-marker',
            'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
        ])));

        $this->assertFalse(StandaloneActivityHostType::isHostRun(new WorkflowRun([
            'workflow_type' => 'example.workflow',
            'workflow_class' => 'ExampleWorkflow',
        ])));

        $this->assertFalse(StandaloneActivityHostType::isHostRun(null));
    }

    private function fileContents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);

        $this->assertIsString($contents);

        return $contents;
    }
}
