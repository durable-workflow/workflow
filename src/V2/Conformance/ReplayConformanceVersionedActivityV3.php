<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type(self::TYPE_KEY)]
final class ReplayConformanceVersionedActivityV3 extends Activity
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance-versioned-v3-activity';

    public function handle(): string
    {
        return 'v3_result';
    }
}
