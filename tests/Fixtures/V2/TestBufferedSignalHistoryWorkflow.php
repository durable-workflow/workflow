<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-buffered-signal-history-workflow')]
#[Signal('append')]
final class TestBufferedSignalHistoryWorkflow extends Workflow
{
    /**
     * @return list<string>
     */
    public function handle(int $target): array
    {
        $values = [];

        for ($index = 0; $index < $target; $index++) {
            $values[] = signal('append');
        }

        return $values;
    }
}
