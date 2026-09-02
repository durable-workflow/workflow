<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Activity;

final class TestNamedFailingActivity extends Activity
{
    public function handle(string $message): string
    {
        throw new RuntimeException($message);
    }
}
