<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Activity;

final class TestContinuationGrowthActivity extends Activity
{
    public function handle(int $round): array
    {
        DB::table('continuation_effects')->insert([
            'run_id' => $this->runId(),
            'activity_id' => $this->activityId(),
            'round' => $round,
        ]);

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
