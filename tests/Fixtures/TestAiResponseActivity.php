<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestAiResponseActivity extends Activity
{
    public function execute(string $message): string
    {
        return "Echo: {$message}";
    }
}
