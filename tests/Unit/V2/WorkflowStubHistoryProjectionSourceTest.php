<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class WorkflowStubHistoryProjectionSourceTest extends TestCase
{
    public function testWorkflowStubDoesNotCallRunSummaryProjectorDirectly(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/src/V2/WorkflowStub.php');

        $this->assertIsString($contents);
        $this->assertStringContainsString('HistoryProjectionRole', $contents);
        $this->assertStringNotContainsString(
            'RunSummaryProjector::project(',
            $contents,
            'WorkflowStub must resolve projections through HistoryProjectionRole.',
        );
    }
}
