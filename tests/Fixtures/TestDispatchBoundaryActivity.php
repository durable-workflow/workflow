<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestDispatchBoundaryActivity extends Activity
{
    public $tries = 1;

    public function execute(string $directory): bool
    {
        file_put_contents($directory . '/executed', '1', FILE_APPEND);

        return true;
    }
}
