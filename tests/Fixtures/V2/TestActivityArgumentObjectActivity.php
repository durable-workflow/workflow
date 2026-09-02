<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestActivityArgumentObjectActivity extends Activity
{
    public function handle(TestActivityArgumentObject $object): string
    {
        // The activity is typed on the value object; if argument serialization
        // dropped class info we would get a plain array here and PHP's
        // parameter type check would have thrown before reaching this body.
        return sprintf('%s:%d:%s', $object->tag, $object->count, $object::class);
    }
}
