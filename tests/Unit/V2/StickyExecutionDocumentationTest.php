<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;

final class StickyExecutionDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/sticky-execution.md';

    public function testContractNamesRequiredStickyExecutionSurface(): void
    {
        $document = $this->document();

        foreach ([
            'sticky-cache lifecycle',
            'routing identity',
            'worker_id',
            'sticky_worker_id',
            'sticky_until',
            'cache misses',
            'worker restart',
            'draining',
            'DW_V2_STICKY_EXECUTION_ENABLED',
            'DW_V2_STICKY_EXECUTION_TTL_SECONDS',
            'hit_rate_last_minute',
            'miss_rate_last_minute',
            'forced_cold_replay_last_minute',
            'capacity pressure',
            'process-local state for correctness',
            'Cold replay is mandatory fallback',
        ] as $needle) {
            $this->assertStringContainsString($needle, $document);
        }
    }

    private function document(): string
    {
        $document = file_get_contents(dirname(__DIR__, 3) . '/' . self::DOCUMENT);

        $this->assertIsString($document);

        return $document;
    }
}
