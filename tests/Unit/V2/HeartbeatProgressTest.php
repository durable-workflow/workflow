<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use Tests\TestCase;
use Workflow\V2\Support\HeartbeatProgress;

final class HeartbeatProgressTest extends TestCase
{
    public function testNormalizeForWriteReturnsBoundedStructuredProgress(): void
    {
        $this->assertSame([
            'message' => 'Polling remote job',
            'current' => 2,
            'total' => 5,
            'unit' => 'chunks',
            'details' => [
                'phase' => 'download',
                'retrying' => false,
            ],
        ], HeartbeatProgress::normalizeForWrite([
            'message' => ' Polling remote job ',
            'current' => 2,
            'total' => 5,
            'unit' => ' chunks ',
            'details' => [
                'phase' => ' download ',
                'retrying' => false,
            ],
        ]));
    }

    public function testNormalizeForWriteRejectsInvalidProgressShape(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Heartbeat progress [unit] requires [current] or [total].');

        HeartbeatProgress::normalizeForWrite([
            'unit' => 'rows',
        ]);
    }

    public function testFromStoredSanitizesUnknownAndInvalidStoredFields(): void
    {
        $this->assertSame([
            'message' => 'Still working',
            'total' => 3,
            'unit' => 'rows',
            'details' => [
                'phase' => 'poll',
            ],
        ], HeartbeatProgress::fromStored([
            'message' => 'Still working',
            'total' => '3',
            'details' => [
                'phase' => 'poll',
                'bad key' => 'ignored',
                'nested' => ['ignored'],
            ],
            'unknown' => 'ignored',
            'unit' => 'rows',
        ]));
    }
}
