<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Support\SearchAttributeReplayIdentity;
use Workflow\V2\Support\UpsertSearchAttributesCall;

final class SearchAttributeReplayIdentityTest extends TestCase
{
    public function testReorderedValuesAndTypesRetainReplayIdentity(): void
    {
        $current = new UpsertSearchAttributesCall([
            'status' => 'ready',
            'attempt' => 2,
        ]);

        SearchAttributeReplayIdentity::assertCompatible(4, [
            'attributes' => [
                'status' => 'ready',
                'attempt' => 2,
            ],
            'attribute_types' => [
                'status' => 'keyword',
                'attempt' => 'int',
            ],
        ], $current);

        $this->addToAssertionCount(1);
    }

    public function testLegacyValueOnlyHistoryRemainsReplayCompatible(): void
    {
        SearchAttributeReplayIdentity::assertCompatible(4, [
            'attributes' => [
                'status' => 'ready',
            ],
        ], new UpsertSearchAttributesCall([
            'status' => 'ready',
        ]));

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('mismatchedPayloads')]
    public function testReplayRejectsDifferentValuesOrTypeIdentity(array $payload, string $message): void
    {
        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage($message);

        SearchAttributeReplayIdentity::assertCompatible(
            7,
            $payload,
            new UpsertSearchAttributesCall([
                'status' => 'ready',
            ]),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mismatchedPayloads(): iterable
    {
        yield 'missing attributes' => [[], 'Recorded search attribute values do not match'];
        yield 'non-map attributes' => [[
            'attributes' => 'ready',
        ], 'Recorded search attribute values do not match'];
        yield 'different values' => [[
            'attributes' => [
                'status' => 'waiting',
            ],
        ], 'Recorded search attribute values do not match'];
        yield 'non-map type identity' => [[
            'attributes' => [
                'status' => 'ready',
            ],
            'attribute_types' => 'keyword',
        ], 'Recorded search attribute types do not match'];
        yield 'different type identity' => [[
            'attributes' => [
                'status' => 'ready',
            ],
            'attribute_types' => [
                'status' => 'string',
            ],
        ], 'Recorded search attribute types do not match'];
    }
}
