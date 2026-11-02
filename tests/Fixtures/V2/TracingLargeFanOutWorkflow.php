<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TracingLargeFanOutWorkflow extends Workflow
{
    public function handle(): array
    {
        $members = [];
        foreach (range(0, 63) as $member) {
            $members[] = static fn (): mixed => self::activity(TracingLargeFanOutActivity::class, $member);
        }

        return self::all($members);
    }
}
