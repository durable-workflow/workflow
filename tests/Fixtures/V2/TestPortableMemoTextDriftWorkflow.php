<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestPortableMemoTextDriftWorkflow extends Workflow
{
    public function handle(): void
    {
        $entries = TestPortableMemoWorkflow::entries(reordered: true);
        $entries['binary_text_value'] = 'same-bytes';

        Workflow::upsertMemo($entries);
    }
}
