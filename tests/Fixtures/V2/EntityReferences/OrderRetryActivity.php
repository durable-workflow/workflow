<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\Activity;

final class OrderRetryActivity extends Activity
{
    public int $tries = 2;

    public function backoff(): array
    {
        return [0];
    }

    public function handle(Order $order, string $note): string
    {
        if ($this->attemptCount() === 1) {
            throw new \RuntimeException('Retry after the order changes.');
        }
        return $order->id . ':' . $order->status . ':' . $note;
    }
}
