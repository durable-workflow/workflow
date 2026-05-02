<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class StaleProjectionRoleRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function projectorPaths(): iterable
    {
        $base = dirname(__DIR__, 3) . '/src/V2/Support';

        yield 'timeline' => [$base . '/RunTimelineProjector.php'];
        yield 'wait' => [$base . '/RunWaitProjector.php'];
        yield 'timer' => [$base . '/RunTimerProjector.php'];
        yield 'lineage' => [$base . '/RunLineageProjector.php'];
    }

    /**
     * @dataProvider projectorPaths
     */
    public function testProjectorRoutesPruningThroughMaintenanceRole(string $path): void
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringContainsString('HistoryProjectionMaintenanceRole', $contents);
        $this->assertStringContainsString('pruneStaleProjectionRowsForRun', $contents);
        $this->assertStringNotContainsString(
            'StaleProjectionCleanup::forRun(',
            $contents,
            sprintf(
                'Projector [%s] must route stale projection pruning through HistoryProjectionMaintenanceRole.',
                basename($path),
            ),
        );
    }
}
