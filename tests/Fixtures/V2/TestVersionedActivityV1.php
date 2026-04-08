<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestVersionedActivityV1 extends Activity
{
    public function execute(): string
    {
        return 'v1_result';
    }
}
