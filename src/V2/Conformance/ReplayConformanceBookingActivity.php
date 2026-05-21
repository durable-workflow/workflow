<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type(self::TYPE_KEY)]
final class ReplayConformanceBookingActivity extends Activity
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance-booking-activity';

    public function handle(string $service): string
    {
        return "{$service}-id-456";
    }
}
