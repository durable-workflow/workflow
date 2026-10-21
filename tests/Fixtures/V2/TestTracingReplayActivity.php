<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestTracingReplayActivity extends Activity
{
    public function handle(): array
    {
        return [9007199254740993, null, '雪'];
    }
}
