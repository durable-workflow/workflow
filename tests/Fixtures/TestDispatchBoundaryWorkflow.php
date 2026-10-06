<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use function Workflow\activity;
use Workflow\Workflow;

final class TestDispatchBoundaryWorkflow extends Workflow
{
    public function execute(string $directory)
    {
        return yield activity(TestDispatchBoundaryActivity::class, $directory);
    }

    public function middleware()
    {
        return [...parent::middleware(), new class() {
            public function handle($job, $next)
            {
                $result = $next($job);
                $directory = $job->arguments[0];

                if (! is_file($directory . '/produced')) {
                    touch($directory . '/produced');
                    $deadline = microtime(true) + 5;
                    while (! is_file($directory . '/release')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Workflow dispatch boundary was not released.');
                        }
                        usleep(10000);
                    }
                }

                return $result;
            }
        }];
    }
}
