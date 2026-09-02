<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class TaskDispatcherHistoryProjectionSourceTest extends TestCase
{
    public function testTaskDispatcherDoesNotCallRunSummaryProjectorDirectly(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/src/V2/Support/TaskDispatcher.php');

        $this->assertIsString($contents);
        $this->assertStringContainsString('HistoryProjectionRole', $contents);
        $this->assertStringNotContainsString(
            'RunSummaryProjector::project(',
            $contents,
            'TaskDispatcher must resolve projections through HistoryProjectionRole.',
        );
    }
}
