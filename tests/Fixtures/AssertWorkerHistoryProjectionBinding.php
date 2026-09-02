<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\HistoryProjectionRole;

final class AssertWorkerHistoryProjectionBinding implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $marker,
    ) {
    }

    public function handle(Application $app): void
    {
        $worker = (string) getmypid();

        Cache::lock($this->marker . ':participants-lock', 5)->block(5, function () use ($worker): void {
            $participants = Cache::get($this->marker . ':participants', []);
            $participants = is_array($participants) ? $participants : [];
            $participants[$worker] = true;

            Cache::put($this->marker . ':participants', $participants, 60);
        });

        $deadline = hrtime(true) + 5_000_000_000;

        do {
            $participants = Cache::get($this->marker . ':participants', []);

            if (is_array($participants) && count($participants) >= 2) {
                break;
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                $this->fail('Both concurrent queue workers did not reach the binding assertion barrier.');
            }

            usleep((int) min(50_000, max(1, (int) ceil($remainingNanoseconds / 1_000))));
        } while (true);

        if (! $app->getProvider(WorkflowServiceProvider::class) instanceof WorkflowServiceProvider) {
            $this->fail('The queue worker did not auto-discover WorkflowServiceProvider.');
        }

        if (! $app->bound(HistoryProjectionRole::class)) {
            $this->fail('WorkflowServiceProvider did not bind HistoryProjectionRole in the queue worker.');
        }

        $role = $app->make(HistoryProjectionRole::class);

        if (! $role instanceof HistoryProjectionRole) {
            $this->fail('The queue worker resolved an invalid HistoryProjectionRole implementation.');
        }

        $servicesCache = $app->getCachedServicesPath();
        $packagesCache = $app->getCachedPackagesPath();

        if (! is_file($servicesCache) || ! is_file($packagesCache)) {
            $this->fail('The queue worker did not compile its isolated Laravel manifests.');
        }

        Cache::lock($this->marker . ':success-lock', 5)->block(5, function () use (
            $worker,
            $servicesCache,
            $packagesCache,
        ): void {
            $successes = Cache::get($this->marker . ':successes', []);
            $successes = is_array($successes) ? $successes : [];
            $successes[$worker] = [
                'services_cache' => $servicesCache,
                'packages_cache' => $packagesCache,
            ];

            Cache::put($this->marker . ':successes', $successes, 60);
        });
    }

    private function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);

        throw new RuntimeException($message);
    }
}
