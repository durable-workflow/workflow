<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestCompletionWakeOtherActivity extends Activity
{
    public function execute(string $directory): string
    {
        file_put_contents($directory . '/other-running', '1');
        TestCompletionWakeActivity::awaitFile($directory . '/release-other');

        return 'other';
    }
}
