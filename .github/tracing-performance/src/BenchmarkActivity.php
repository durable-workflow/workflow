<?php

declare(strict_types=1);

namespace TracingBenchmark;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;

final class BenchmarkActivity extends Activity
{
    public function handle(int $member): array
    {
        DB::table('benchmark_callbacks')->insert([
            'activity_id' => $this->activityId(),
            'run_id' => $this->runId(),
            'member' => $member,
        ]);
        Log::info('benchmark callback', ['member' => $member]);

        return ['member' => $member, 'value' => [9007199254740993, null, '雪']];
    }
}
