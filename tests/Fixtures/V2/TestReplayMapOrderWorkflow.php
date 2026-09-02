<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-replay-map-order-workflow')]
final class TestReplayMapOrderWorkflow extends Workflow
{
    /**
     * @return array{top_level_keys: list<int|string>, nested_keys: list<int|string>}
     */
    public function handle(): array
    {
        $value = activity(TestGreetingActivity::class, 'map-order');

        if (! is_array($value) || ! isset($value['outer'][0]) || ! is_array($value['outer'][0])) {
            throw new LogicException('The replay map-order fixture requires a nested map result.');
        }

        return [
            'top_level_keys' => array_keys($value),
            'nested_keys' => array_keys($value['outer'][0]),
        ];
    }
}
