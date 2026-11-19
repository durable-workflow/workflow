<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\EntityReferences\EntityReferenceAdapter;

final class OrderReferenceAdapter implements EntityReferenceAdapter
{
    public function supports(object $entity): bool
    {
        return $entity instanceof Order;
    }

    public function identify(object $entity): string
    {
        /** @var Order $entity */
        return $entity->id;
    }

    public function version(): int
    {
        return 1;
    }
}
