<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Activity;

final class TestFinallyWorkActivity extends Activity
{
    public function handle(bool $fail): string
    {
        if ($fail) {
            throw new RuntimeException('work failed');
        }

        return 'success';
    }
}
