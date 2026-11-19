<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('test-fan-out-payload-activity')]
final class TestFanOutPayloadActivity extends Activity
{
    public function handle(int $index, string $payload): int
    {
        return strlen($payload);
    }
}
