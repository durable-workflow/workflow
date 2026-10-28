<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Exception;
use Workflow\Activity;

final class TestSingleTryExceptionActivity extends Activity
{
    // Overlap releases consume reservations without executing this exception.
    public $maxExceptions = 1;

    public function execute($shouldThrow)
    {
        if ($shouldThrow) {
            throw new Exception('failed');
        }

        return 'activity';
    }
}
