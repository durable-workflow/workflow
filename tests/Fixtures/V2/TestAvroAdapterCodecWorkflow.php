<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use function Workflow\V2\activity;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

final class TestAvroAdapterCodecWorkflow extends Workflow
{
    public function handle(): AvroMapValue
    {
        $arguments = [null, true, 7, 7.0, AvroBinaryValue::fromBytes("\x00\xFF"), 'text'];

        $activityResult = activity(TestAvroAdapterCodecActivity::class, ...$arguments);
        if (! $activityResult instanceof AvroMapValue) {
            throw new LogicException('The typed Avro activity result did not round-trip.');
        }

        $childResult = child(TestAvroAdapterCodecChildWorkflow::class, ...$arguments);
        if (! $childResult instanceof AvroMapValue) {
            throw new LogicException('The typed Avro child result did not round-trip.');
        }

        return $childResult;
    }
}
