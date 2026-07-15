<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class TerminateQueueWorker implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $completionMarker,
    ) {
    }

    public function handle(): void
    {
        fwrite(STDOUT, str_repeat('intentional worker stdout padding ', 8_192));
        fwrite(STDERR, str_repeat('intentional worker stderr padding ', 8_192));
        fwrite(STDOUT, "intentional worker stdout probe\n");
        fwrite(STDERR, "intentional worker stderr probe\n");
        Cache::put($this->completionMarker, true, 60);

        exit(23);
    }
}
