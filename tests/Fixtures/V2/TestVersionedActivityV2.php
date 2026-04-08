<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestVersionedActivityV2 extends Activity
{
    public function execute(): string
    {
        return 'v2_result';
    }
}
