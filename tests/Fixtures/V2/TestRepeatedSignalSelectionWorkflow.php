<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

#[Signal('earlier')]
#[Signal('later')]
final class TestRepeatedSignalSelectionWorkflow extends Workflow
{
    public function handle(): array
    {
        $results = [];
        for ($round = 0; $round < 2; ++$round) {
            $selected = self::select([
                'earlier' => static fn () => self::await('earlier', 3600),
                'later' => static fn () => self::await('later', 3600),
            ]);
            $selected->result();
            foreach ($selected->remaining() as $handle) {
                $handle->cancel();
            }
            $results[] = $selected->key;
        }
        return $results;
    }
}
