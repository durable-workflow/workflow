<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class WorkflowStubHistoryProjectionSourceTest extends TestCase
{
    private const WORKFLOW_STUB_PATH = '/src/V2/WorkflowStub.php';

    public function testWorkflowStubDoesNotCallRunSummaryProjectorDirectly(): void
    {
        $contents = $this->workflowStubSource();

        $this->assertStringContainsString('HistoryProjectionRole', $contents);
        $this->assertStringNotContainsString(
            'RunSummaryProjector::project(',
            $contents,
            'WorkflowStub must resolve projections through HistoryProjectionRole.',
        );
    }

    public function testProjectRunHelperIsTheSoleHistoryProjectionRoleEntryPoint(): void
    {
        $contents = $this->workflowStubSource();

        $this->assertMatchesRegularExpression(
            '/private static function projectRun\(WorkflowRun \$run, array \$with = \[\]\): WorkflowRunSummary/',
            $contents,
            'projectRun() must accept the relation list so call sites do not hydrate inline.',
        );

        $this->assertStringContainsString(
            'self::historyProjectionRole()->projectRun($run)',
            $contents,
            'projectRun() must dispatch into the HistoryProjectionRole contract.',
        );

        $this->assertSame(
            1,
            substr_count($contents, 'historyProjectionRole()->projectRun('),
            'Only the projectRun() helper may dispatch into HistoryProjectionRole::projectRun().',
        );
    }

    public function testCallSitesDoNotInlineRelationHydrationBeforeProjection(): void
    {
        $contents = $this->workflowStubSource();

        $this->assertDoesNotMatchRegularExpression(
            '/->fresh\(\[[^\]]*\]\)\s*\n?\s*\?\?\s*\$\w+\s*\)/',
            $contents,
            'Projection call sites must not inline `->fresh([...]) ?? $run` — '
                . 'the projectRun() helper owns relation hydration.',
        );
    }

    public function testStandardProjectionRelationsConstantsExist(): void
    {
        $contents = $this->workflowStubSource();

        $this->assertStringContainsString('PROJECTION_RUN_RELATIONS', $contents);
        $this->assertStringContainsString('PROJECTION_RUN_RELATIONS_WITH_CHILDREN', $contents);
    }

    private function workflowStubSource(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::WORKFLOW_STUB_PATH);

        $this->assertIsString($contents);

        return $contents;
    }
}
