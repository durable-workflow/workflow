<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\AvroValueJsonProjection;

final class AvroValueJsonProjectionTest extends TestCase
{
    public function testBytesAndAmbiguousMapsHaveJsonSafeTypedShapes(): void
    {
        $value = [
            AvroBinaryValue::fromBytes("\x00\xFF"),
            AvroMapValue::fromPairs([]),
            AvroMapValue::fromPairs([['0', AvroBinaryValue::fromBytes('nested')], ['1', ['list']]]),
        ];

        $projection = AvroValueJsonProjection::project($value);

        self::assertSame([
            [
                '$type' => 'bytes',
                'base64' => 'AP8=',
            ],
            [
                '$type' => 'map',
                'entries' => [],
            ],
            [
                '$type' => 'map',
                'entries' => [
                    [
                        'key' => '0',
                        'value' => [
                            '$type' => 'bytes',
                            'base64' => 'bmVzdGVk',
                        ],
                    ],
                    [
                        'key' => '1',
                        'value' => ['list'],
                    ],
                ],
            ],
        ], $projection);
        self::assertJson(json_encode($projection, JSON_THROW_ON_ERROR));
    }
}
