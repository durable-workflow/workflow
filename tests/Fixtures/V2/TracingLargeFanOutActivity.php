<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;

final class TracingLargeFanOutActivity extends Activity
{
    public int $tries = 1;

    public function handle(int $member): array
    {
        Log::info('large callback', [
            'member' => $member,
            'actor_pid' => getmypid(),
        ]);

        return [
            'member' => $member,
            'value' => [9007199254740993, null, '雪'],
        ];
    }
}
