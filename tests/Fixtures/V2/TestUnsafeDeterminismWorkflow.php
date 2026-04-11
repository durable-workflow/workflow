<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workflow\V2\Workflow;

final class TestUnsafeDeterminismWorkflow extends Workflow
{
    public function handle(): string
    {
        $value = DB::table('orders')->count();
        $cached = Cache::get('approval');
        $request = request();
        $now = now();
        $random = random_int(1, 100);
        $id = Str::uuid();

        if ($value > 0 && $cached !== null && $request !== null && $now !== null && $random > 0 && $id !== null) {
            return 'unsafe';
        }

        return 'safe';
    }
}
