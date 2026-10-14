<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class SqliteContentionActivity extends Activity
{
    public static int $invocations = 0;

    public function handle(): array
    {
        self::$invocations++;

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
