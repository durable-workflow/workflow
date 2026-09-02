<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;

final class TestReplayedDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $channel,
    ) {
        parent::__construct(sprintf('Order %s rejected via %s', $orderId, $channel), 422);
    }
}
