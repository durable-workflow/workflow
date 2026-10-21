<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Workflow\V2\Activity;

final class TracingTerminalFailureActivity extends Activity
{
    public int $tries = 1;

    public function handle(): never
    {
        Log::info('terminal failure callback');
        throw new RuntimeException('deliberate terminal activity failure');
    }
}
