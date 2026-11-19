<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\EntityReferences\EntityReference;

final class EntityReferenceTest extends TestCase
{
    public function testPortableMapKeyOrderDoesNotChangeTheReference(): void
    {
        $reference = EntityReference::fromArray([
            'version' => 1,
            'id' => 'order-1',
            'type' => 'orders.order',
        ]);

        self::assertSame('orders.order', $reference->type);
        self::assertSame('order-1', $reference->id);
        self::assertSame(1, $reference->version);
        self::assertSame([
            'type' => 'orders.order',
            'id' => 'order-1',
            'version' => 1,
        ], $reference->toArray());
    }

    public static function invalidReferences(): iterable
    {
        yield 'missing type' => [[
            'id' => 'order-1',
            'version' => 1,
        ]];
        yield 'extra field' => [[
            'type' => 'orders.order',
            'id' => 'order-1',
            'version' => 1,
            'state' => 'paid',
        ]];
        yield 'unknown field replaces type' => [[
            'name' => 'orders.order',
            'id' => 'order-1',
            'version' => 1,
        ]];
        yield 'string version' => [[
            'type' => 'orders.order',
            'id' => 'order-1',
            'version' => '1',
        ]];
        yield 'empty id' => [[
            'type' => 'orders.order',
            'id' => '',
            'version' => 1,
        ]];
        yield 'invalid version' => [[
            'type' => 'orders.order',
            'id' => 'order-1',
            'version' => 0,
        ]];
    }

    #[DataProvider('invalidReferences')]
    public function testMalformedReferencesRemainRefused(array $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        EntityReference::fromArray($value);
    }
}
