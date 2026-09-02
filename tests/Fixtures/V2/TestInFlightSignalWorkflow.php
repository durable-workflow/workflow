<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-in-flight-signal-workflow')]
#[Signal('advance')]
#[Signal('finish')]
final class TestInFlightSignalWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $mode): array
    {
        if ($mode === 'signal-wait') {
            return [
                'mode' => $mode,
                'value' => signal('advance'),
            ];
        }

        await(static fn (): bool => false, 'approval.ready');

        return [
            'mode' => $mode,
            'approved' => true,
        ];
    }
}
