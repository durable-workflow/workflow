<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

abstract class TestMixedEntryActivityParent extends Activity
{
    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}

#[Type('test-mixed-entry-activity')]
final class TestMixedEntryActivity extends TestMixedEntryActivityParent
{
    public function execute(string $name): string
    {
        return "Hello, {$name}!";
    }
}
