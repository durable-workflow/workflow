<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use InvalidArgumentException;
use RuntimeException;
use Workflow\Activity;

final class TestProbeRetryActivity extends Activity
{
    public $tries = 1;

    public function execute(int $attempt): string
    {
        return match ($attempt) {
            1 => throw new RuntimeException('first failure'),
            2 => throw new InvalidArgumentException('second failure'),
            default => 'success',
        };
    }
}
