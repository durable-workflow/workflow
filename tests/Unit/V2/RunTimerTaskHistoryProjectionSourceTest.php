<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class RunTimerTaskHistoryProjectionSourceTest extends TestCase
{
    private const RUN_TIMER_TASK_PATH = '/src/V2/Jobs/RunTimerTask.php';

    public function testRunTimerTaskDoesNotCallRunSummaryProjectorDirectly(): void
    {
        $contents = $this->runTimerTaskSource();

        $this->assertStringContainsString('HistoryProjectionRole', $contents);
        $this->assertStringNotContainsString(
            'RunSummaryProjector::project(',
            $contents,
            'RunTimerTask must resolve projections through HistoryProjectionRole.',
        );
    }

    public function testProjectRunHelperIsTheSoleHistoryProjectionRoleEntryPoint(): void
    {
        $contents = $this->runTimerTaskSource();

        $this->assertMatchesRegularExpression(
            '/private function projectRun\(WorkflowRun \$run, array \$with = \[\]\): void/',
            $contents,
            'projectRun() must accept the relation list so call sites do not hydrate inline.',
        );

        $this->assertStringContainsString(
            '$this->historyProjectionRole()',
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
        $contents = $this->runTimerTaskSource();

        $this->assertDoesNotMatchRegularExpression(
            '/->fresh\(\[[^\]]*\]\)\s*\n?\s*\?\?\s*\$\w+\s*\)/',
            $contents,
            'Projection call sites must not inline `->fresh([...]) ?? $run` — '
                . 'the projectRun() helper owns relation hydration.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/projectRun\(\s*\$\w+->fresh\(/',
            $contents,
            'Projection call sites must not inline `$run->fresh([...])` — '
                . 'pass relations to the projectRun() helper instead.',
        );
    }

    public function testStandardProjectionRelationsConstantsExist(): void
    {
        $contents = $this->runTimerTaskSource();

        $this->assertStringContainsString('PROJECTION_RUN_RELATIONS', $contents);
        $this->assertStringContainsString('PROJECTION_RUN_RELATIONS_WITH_HISTORY_EVENTS', $contents);
    }

    private function runTimerTaskSource(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::RUN_TIMER_TASK_PATH);

        $this->assertIsString($contents);

        return $contents;
    }
}
