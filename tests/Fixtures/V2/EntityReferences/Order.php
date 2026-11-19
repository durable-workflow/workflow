<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

/**
 * A plain domain entity: no Durable Workflow or Laravel dependency (#996).
 */
final class Order
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
    ) {
    }
}
