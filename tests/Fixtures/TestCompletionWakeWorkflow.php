<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Workflow;

use function Workflow\{activity, all};

final class TestCompletionWakeWorkflow extends Workflow
{
    public function execute(string $directory)
    {
        $results = yield all([
            activity(TestCompletionWakeActivity::class, $directory),
            activity(TestCompletionWakeOtherActivity::class, $directory),
        ]);

        return 'workflow_' . $results[0] . '_' . $results[1];
    }

    public function middleware(): array
    {
        return [new class($this->arguments[0]) {
            public function __construct(
                private readonly string $directory
            ) {
            }

            public function handle($job, $next): void
            {
                $next($job);
                if (! is_file($this->directory . '/pause-parent')) {
                    return;
                }
                file_put_contents($this->directory . '/parent-returning', '1');
                TestCompletionWakeActivity::awaitFile($this->directory . '/release-parent');
            }
        }, ...parent::middleware()];
    }
}
