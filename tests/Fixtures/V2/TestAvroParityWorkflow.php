<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-avro-parity-workflow')]
#[Signal('order-updated')]
final class TestAvroParityWorkflow extends Workflow
{
    public function handle(string $orderId, float $amount, int $itemsCount): array
    {
        $activityResult = activity(TestAvroParityActivity::class, $orderId, $amount, $itemsCount);

        $signalPayload = await('order-updated');

        return [
            'order_id' => $orderId,
            'input_amount' => $amount,
            'input_items_count' => $itemsCount,
            'activity_result' => $activityResult,
            'signal_payload' => $signalPayload,
            'three_point_zero' => 3.0,
        ];
    }
}
