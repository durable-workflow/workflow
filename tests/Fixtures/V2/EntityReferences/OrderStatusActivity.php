<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('tests.entity-references.order-status')]
final class OrderStatusActivity extends Activity
{
    public function handle(Order $order, string $note): string
    {
        return "{$order->id}:{$order->status}:{$note}";
    }
}
