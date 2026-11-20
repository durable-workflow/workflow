<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Exceptions\ExternalSignalRefusedException;
use function Workflow\V2\sideEffect;
use function Workflow\V2\signalExternalWorkflow;
use Workflow\V2\Workflow;

#[Type('test-signal-external-cleanup-workflow')]
#[Signal('continue')]
final class TestSignalExternalCleanupWorkflow extends Workflow
{
    public function handle(string $target): void
    {
        try {
            await('continue', timeout: 3600);
        } finally {
            try {
                signalExternalWorkflow($target, 'approve', ['cleanup']);
                $reason = 'sent';
            } catch (ExternalSignalRefusedException $refused) {
                $reason = $refused->reason;
            }

            sideEffect(static fn (): string => $reason);
        }
    }
}
