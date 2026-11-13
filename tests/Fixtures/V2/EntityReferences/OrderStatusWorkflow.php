<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\localActivity;
use Workflow\V2\Workflow;

#[Type('tests.entity-references.order-status-workflow')]
final class OrderStatusWorkflow extends Workflow
{
    public function handle(string $orderId, string $mode = 'queued'): string
    {
        $order = new Order($orderId, 'as-seen-by-workflow');
        if ($mode === 'local') {
            return localActivity(OrderStatusActivity::class, $order, 'checked');
        }
        if ($mode === 'map') {
            return activity(OrderMapActivity::class, [
                'type' => 'orders.order',
                'id' => $orderId,
                'version' => 1,
            ], 'checked');
        }
        $activity = match ($mode) {
            'injected' => OrderInjectedActivity::class,
            'mismatch' => OrderMismatchActivity::class,
            'retry' => OrderRetryActivity::class,
            default => OrderStatusActivity::class,
        };
        return activity($activity, $order, 'checked');
    }
}
