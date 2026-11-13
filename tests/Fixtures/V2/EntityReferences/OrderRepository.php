<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

/**
 * In-memory stand-in for an application repository, counting loads so tests
 * can prove replay never reaches it.
 */
final class OrderRepository
{
    /**
     * @var array<string, Order>
     */
    public static array $orders = [];

    public static int $loads = 0;

    public static array $contexts = [];

    public static array $transactionLevels = [];

    public static bool $deny = false;

    public static function reset(): void
    {
        self::$orders = [];
        self::$loads = 0;
        self::$contexts = [];
        self::$transactionLevels = [];
        self::$deny = false;
    }

    public static function save(Order $order): void
    {
        self::$orders[$order->id] = $order;
    }

    public static function find(string $id): ?Order
    {
        ++self::$loads;

        return self::$orders[$id] ?? null;
    }
}
