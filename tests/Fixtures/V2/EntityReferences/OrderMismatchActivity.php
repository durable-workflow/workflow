<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\Activity;

final class OrderMismatchActivity extends Activity
{
    public function handle(string $order, string $note): string
    {
        throw new \LogicException('A refused reference must never enter this method.');
    }
}
