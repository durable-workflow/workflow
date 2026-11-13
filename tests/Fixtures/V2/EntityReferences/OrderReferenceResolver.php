<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\EntityReferences\EntityAccessDenied;
use Workflow\V2\EntityReferences\EntityNotFound;
use Workflow\V2\EntityReferences\EntityReference;
use Workflow\V2\EntityReferences\EntityReferenceContext;
use Workflow\V2\EntityReferences\EntityReferenceResolver;

final class OrderReferenceResolver implements EntityReferenceResolver
{
    public function resolve(EntityReference $reference, EntityReferenceContext $context): object
    {
        OrderRepository::$contexts[] = $context;
        OrderRepository::$transactionLevels[] = \Illuminate\Support\Facades\DB::transactionLevel();
        if (OrderRepository::$deny) {
            throw EntityAccessDenied::for($reference);
        }
        return OrderRepository::find($reference->id) ?? throw EntityNotFound::for($reference);
    }
}
