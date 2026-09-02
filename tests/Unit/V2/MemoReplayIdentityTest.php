<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\MemoReplayIdentity;

final class MemoReplayIdentityTest extends TestCase
{
    public function testEmptyMemoMapRetainsItsAvroMapBranch(): void
    {
        $envelope = MemoPayload::mapEnvelope([]);

        $this->assertSame([], MemoPayload::decodeEntries($envelope));
        $this->assertSame($envelope, MemoPayload::canonicalMapEnvelope($envelope));
    }

    public function testMalformedRecordedEnvelopeFailsAsReplayMismatch(): void
    {
        $this->expectException(HistoryEventShapeMismatchException::class);

        MemoReplayIdentity::assertCompatible(4, [
            'codec' => 'avro',
            'blob' => base64_encode('not-an-avro-single-object'),
        ], [
            'status' => 'waiting',
        ]);
    }

    public function testEquivalentReorderedMapsHaveTheSameReplayIdentity(): void
    {
        MemoReplayIdentity::assertCompatible(
            3,
            MemoPayload::envelope([
                'native' => [
                    'second' => 2,
                    'first' => 1,
                ],
                'adapted' => AvroMapValue::fromPairs([
                    ['second', AvroMapValue::fromPairs([
                        ['right', AvroBinaryValue::fromBytes('value')],
                        ['left', 1],
                    ])],
                    ['first', true],
                ]),
            ]),
            [
                'adapted' => AvroMapValue::fromPairs([
                    ['first', true],
                    ['second', AvroMapValue::fromPairs([
                        ['left', 1],
                        ['right', AvroBinaryValue::fromBytes('value')],
                    ])],
                ]),
                'native' => [
                    'first' => 1,
                    'second' => 2,
                ],
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testLongAndDoubleValuesHaveDifferentReplayIdentities(): void
    {
        $this->expectException(HistoryEventShapeMismatchException::class);

        MemoReplayIdentity::assertCompatible(3, MemoPayload::envelope([
            'attempt' => 7,
        ]), [
            'attempt' => 7.0,
        ]);
    }

    public function testDifferentBinaryValuesHaveDifferentReplayIdentities(): void
    {
        $this->expectException(HistoryEventShapeMismatchException::class);

        MemoReplayIdentity::assertCompatible(
            3,
            MemoPayload::envelope([
                'payload' => AvroBinaryValue::fromBytes("\x00\xFF"),
            ]),
            [
                'payload' => AvroBinaryValue::fromBytes("\x00\xFE"),
            ],
        );
    }

    public function testTextAndBinaryValuesHaveDifferentReplayIdentities(): void
    {
        $this->expectException(HistoryEventShapeMismatchException::class);

        MemoReplayIdentity::assertCompatible(
            3,
            MemoPayload::envelope([
                'payload' => 'same-bytes',
            ]),
            [
                'payload' => AvroBinaryValue::fromBytes('same-bytes'),
            ],
        );
    }
}
