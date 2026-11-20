<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

#[Type('test-signal-external-child-handle-workflow')]
final class TestSignalExternalChildHandleWorkflow extends Workflow
{
    public function handle(string $value): array
    {
        $child = startChild(TestSignalExternalTargetWorkflow::class);
        $signal = $this->lastChild()?->signalDurably('approve', $value);

        return [
            'signal' => $signal?->toArray(),
            'child' => $child->await(),
        ];
    }
}
