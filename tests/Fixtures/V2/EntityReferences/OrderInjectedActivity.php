<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Psr\Log\LoggerInterface;
use Workflow\V2\Activity;

final class OrderInjectedActivity extends Activity
{
    public function handle(LoggerInterface $logger, Order $order, string $note): string
    {
        $logger->debug('Order reference activity ran.');
        return $order->id . ':' . $order->status . ':' . $note;
    }
}
