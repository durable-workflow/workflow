<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

/**
 * One all() of $count child workflows, each given a $bytes-byte argument (#1203).
 */
#[Type('test-fan-out-payload-child-workflow')]
final class TestFanOutPayloadChildWorkflow extends Workflow
{
    public function handle(int $count, int $bytes): array
    {
        $calls = [];

        for ($index = 0; $index < $count; $index++) {
            $payload = str_repeat(chr(97 + ($index % 26)), $bytes);
            $calls[] = static fn () => child(TestFanOutPayloadLeafWorkflow::class, $index, $payload);
        }

        $results = all($calls);

        return [
            'members' => count($results),
            'total_bytes' => array_sum($results),
        ];
    }
}
