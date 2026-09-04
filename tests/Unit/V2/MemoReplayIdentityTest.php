<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\MemoReplayIdentity;

final class MemoReplayIdentityTest extends TestCase
{
    public function testCanonicalEnvelopeNormalizesNestedMapOrder(): void
    {
        $canonical = MemoPayload::canonicalEnvelope(MemoPayload::envelope([
            'second' => [
                'right' => 2,
                'left' => 1,
            ],
            'first' => true,
        ]));

        $this->assertSame(MemoPayload::envelope([
            'first' => true,
            'second' => [
                'left' => 1,
                'right' => 2,
            ],
        ]), $canonical);
    }

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

    public function testMapPayloadHelpersPreservePortableBytes(): void
    {
        $map = AvroMapValue::fromPairs([['payload', AvroBinaryValue::fromBytes("\x00\xFF")]]);
        $envelope = MemoPayload::envelope($map);

        $this->assertSame($envelope, MemoPayload::canonicalMapEnvelope($envelope));
        $this->assertSame("\x00\xFF", MemoPayload::decodeEntries($envelope)['payload']->bytes);
        $this->assertNotSame('', MemoPayload::encodedBytes($map));
        $this->assertNotSame('', MemoPayload::encodedMapBytes([
            'payload' => AvroBinaryValue::fromBytes("\x00\xFF"),
        ]));
    }

    public function testMemoEntryPayloadRejectsInvalidKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_memo_key');

        MemoPayload::decodeEntries(MemoPayload::envelope(AvroMapValue::fromPairs([['123', 'value']])));
    }

    public function testMemoEntryPayloadMustBeAMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must decode to a string-keyed map');

        MemoPayload::decodeEntries(MemoPayload::envelope(['value']));
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[DataProvider('invalidEnvelopes')]
    public function testMalformedMemoEnvelopesAreRejected(array $envelope, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MemoPayload::encodedEnvelopeSize($envelope);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEnvelopes(): iterable
    {
        yield 'nonstandard shape' => [[
            'codec' => 'avro',
            'blob' => 'value',
            'extra' => true,
        ], 'expected exactly the standard'];
        yield 'unsupported codec' => [[
            'codec' => 'json',
            'blob' => 'value',
        ], 'unsupported_payload_codec'];
        yield 'non-string codec' => [[
            'codec' => [],
            'blob' => 'value',
        ], 'memo payloads require codec "avro"'];
        yield 'empty blob' => [[
            'codec' => 'avro',
            'blob' => '',
        ], 'must be a non-empty string'];
        yield 'non-string blob' => [[
            'codec' => 'avro',
            'blob' => [],
        ], 'must be a non-empty string'];
        yield 'invalid base64 blob' => [[
            'codec' => 'avro',
            'blob' => '%%%',
        ], 'must be strict base64'];
    }
}
