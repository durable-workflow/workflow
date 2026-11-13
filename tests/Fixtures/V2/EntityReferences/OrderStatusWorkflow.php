<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('tests.entity-references.order-status-workflow')]
final class OrderStatusWorkflow extends Workflow
{
    public function handle(string $orderId): string
    {
        // Workflow code holds the domain entity; the engine records a reference.
        return activity(OrderStatusActivity::class, new Order($orderId, 'as-seen-by-workflow'), 'checked');
    }
}
