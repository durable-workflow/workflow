<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestContinuationProcessActivity extends Activity
{
    public function handle(string $directory): array
    {
        file_put_contents($directory . '/effects', getmypid() . "\n", FILE_APPEND | LOCK_EX);

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
