<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\Serializers\AvroBinaryValue;
use Workflow\V2\Workflow;

final class TestPortableMemoBinaryContentDriftWorkflow extends Workflow
{
    public function handle(): void
    {
        $entries = TestPortableMemoWorkflow::entries(reordered: true);
        $entries['binary_value'] = AvroBinaryValue::fromBytes("\x00\xFE");

        Workflow::upsertMemo($entries);
    }
}
