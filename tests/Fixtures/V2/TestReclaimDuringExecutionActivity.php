<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Closure;
use Workflow\V2\Activity;

final class TestReclaimDuringExecutionActivity extends Activity
{
    private static ?Closure $callback = null;

    public static function intercept(?Closure $callback): void
    {
        self::$callback = $callback;
    }

    public function execute(string $name): string
    {
        $callback = self::$callback;
        self::$callback = null;

        if ($callback instanceof Closure) {
            $callback($this->execution->id);
        }

        return "Hello, {$name}!";
    }
}
