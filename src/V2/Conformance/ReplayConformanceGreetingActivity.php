<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type(self::TYPE_KEY)]
final class ReplayConformanceGreetingActivity extends Activity
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance-greeting-activity';

    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}
