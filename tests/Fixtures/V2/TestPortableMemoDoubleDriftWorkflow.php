<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestPortableMemoDoubleDriftWorkflow extends Workflow
{
    public function handle(): void
    {
        $entries = TestPortableMemoWorkflow::entries(reordered: true);
        $entries['long_value'] = 7.0;

        Workflow::upsertMemo($entries);
    }
}
