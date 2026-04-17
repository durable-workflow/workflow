<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestAvroParityActivity extends Activity
{
    public function handle(string $orderId, float $amount, int $itemsCount): array
    {
        return [
            "order_id" => $orderId,
            "amount" => $amount * 1.1,
            "items_count" => $itemsCount,
            "three_point_zero" => 3.0,
        ];
    }
}
