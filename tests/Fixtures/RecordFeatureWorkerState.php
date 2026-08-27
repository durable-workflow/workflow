<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RecordFeatureWorkerState implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $marker
    ) {
    }

    public function handle(): void
    {
        Cache::put($this->marker, [
            'pid' => getmypid(),
            'watchdog_enabled' => config('workflows.watchdog.enabled'),
        ], 60);
    }
}
