<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;

use Workflow\V2\Workflow;

#[Signal('advance')]
final class TestRetainedSignalWritesWorkflow extends Workflow
{
    public function handle(int $count): array
    {
        $values = [];
        for ($index = 0; $index < $count; $index++) {
            $values[] = signal('advance');
        }

        return $values;
    }
}
