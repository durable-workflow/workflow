<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestCompletionWakeActivity extends Activity
{
    public function execute(string $directory): string
    {
        file_put_contents($directory . '/activity-running', '1');
        self::awaitFile($directory . '/other-running');
        return 'activity';
    }

    public static function awaitFile(string $path): void
    {
        $stop = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $stop) {
                throw new \RuntimeException('Completion barrier timed out: ' . basename($path));
            }
            usleep(10000);
        }
    }

    public function middleware(): array
    {
        $middleware = parent::middleware();
        array_splice($middleware, 1, 0, [new class($this->arguments[0]) {
            public function __construct(
                private readonly string $directory
            ) {
            }

            public function handle($job, $next): void
            {
                $next($job);
                $complete = $job->onUnlock;
                $job->onUnlock = function (bool $shouldSignal) use ($complete): void {
                    file_put_contents($this->directory . '/activity-unlocked', $shouldSignal ? '1' : '0');
                    TestCompletionWakeActivity::awaitFile($this->directory . '/release-activity');
                    $complete($shouldSignal);
                };
            }
        }]);

        return $middleware;
    }
}
