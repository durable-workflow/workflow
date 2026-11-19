<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * One all() of $count activities, each given a $bytes-byte argument (#1231).
 */
#[Type('test-fan-out-payload-activity-workflow')]
final class TestFanOutPayloadActivityWorkflow extends Workflow
{
    public function handle(int $count, int $bytes): array
    {
        $calls = [];

        for ($index = 0; $index < $count; $index++) {
            $payload = str_repeat(chr(97 + ($index % 26)), $bytes);
            $calls[] = static fn () => activity(TestFanOutPayloadActivity::class, $index, $payload);
        }

        $results = all($calls);

        return [
            'members' => count($results),
            'total_bytes' => array_sum($results),
        ];
    }
}
