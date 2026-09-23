<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\HeartbeatProgress;

final class HeartbeatProgressTest extends NonDatabaseTestCase
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

    #[DataProvider('invalidWriteCases')]
    public function testNormalizeForWriteRejectsInvalidFields(array $progress, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        HeartbeatProgress::normalizeForWrite($progress);
    }

    public static function invalidWriteCases(): array
    {
        return [
            'unknown field' => [[
                'extra' => true,
            ], 'unknown keys: [extra]'],
            'message type' => [[
                'message' => 1,
            ], '[message] must be a string'],
            'blank message' => [[
                'message' => '  ',
            ], '[message] must be a non-empty string'],
            'long message' => [[
                'message' => str_repeat('x', 281),
            ], '[message] must be 280 characters or fewer'],
            'unit type' => [[
                'unit' => 1,
            ], '[unit] must be a string'],
            'blank unit' => [[
                'unit' => '  ',
            ], '[unit] must be a non-empty string'],
            'long unit' => [[
                'unit' => str_repeat('x', 65),
            ], '[unit] must be 64 characters or fewer'],
            'current type' => [[
                'current' => true,
            ], '[current] must be numeric'],
            'nonfinite total' => [[
                'total' => INF,
            ], '[total] must be finite'],
            'negative current' => [[
                'current' => -1,
            ], '[current] must be zero or greater'],
            'details type' => [[
                'details' => 'text',
            ], '[details] must be an object-like array'],
            'details list' => [[
                'details' => [true],
            ], '[details] must use string keys'],
            'details count' => [[
                'details' => array_fill_keys(array_map(static fn (int $n): string => 'key' . $n, range(1, 21)), true),
            ], '[details] supports at most 20 entries'],
            'detail key' => [[
                'details' => [
                    'bad key' => true,
                ],
            ], 'detail key [bad key] must match'],
            'nonfinite detail' => [[
                'details' => [
                    'rate' => NAN,
                ],
            ], 'detail [rate] must be finite'],
            'blank detail' => [[
                'details' => [
                    'phase' => '  ',
                ],
            ], 'detail [phase] must be a non-empty string'],
            'long detail' => [[
                'details' => [
                    'phase' => str_repeat('x', 192),
                ],
            ], 'detail [phase] must be 191 characters or fewer'],
            'nested detail' => [[
                'details' => [
                    'phase' => [],
                ],
            ], 'detail [phase] must be scalar or null'],
        ];
    }

    public function testNumericProgressAndDetailsAreCanonicalizedForWrite(): void
    {
        $this->assertSame([
            'current' => 2,
            'total' => 2.5,
            'unit' => 'items',
            'details' => [
                'done' => true,
                'phase' => 'fetch',
                'ratio' => 1,
                'unknown' => null,
            ],
        ], HeartbeatProgress::normalizeForWrite([
            'current' => '2',
            'total' => '2.5',
            'unit' => ' items ',
            'details' => [
                'ratio' => 1.0,
                'unknown' => null,
                'phase' => ' fetch ',
                'done' => true,
            ],
        ]));
    }

    public function testStoredProgressDropsInvalidFieldsWithoutInventingProgress(): void
    {
        $this->assertNull(HeartbeatProgress::fromStored('not an object'));
        $this->assertNull(HeartbeatProgress::fromStored([]));
        $this->assertNull(HeartbeatProgress::fromStored([
            'unit' => 'rows',
        ]));
        $this->assertNull(HeartbeatProgress::fromStored([
            'message' => '',
            'details' => [],
        ]));

        $this->assertSame([
            'details' => [
                'false' => false,
                'ok' => 1,
            ],
        ], HeartbeatProgress::fromStored([
            'message' => 42,
            'current' => -1,
            'total' => INF,
            'unit' => 'rows',
            'details' => [
                'bad key' => 'discard',
                'nested' => ['discard'],
                'nan' => NAN,
                'empty' => ' ',
                'long' => str_repeat('x', 192),
                'ok' => 1.0,
                'false' => false,
            ],
        ]));
    }
}
