<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Type('test-signal-external-slow-close-workflow')]
#[Signal('ping')]
final class TestSignalExternalSlowCloseWorkflow extends Workflow
{
    public function handle(string $flag, int $sleepMs, string $mode, int $generation = 0): mixed
    {
        if ($generation > 0) {
            return [
                'generation' => $generation,
                'got' => await('ping'),
            ];
        }
        if ($flag !== '' && is_file($flag)) {
            usleep($sleepMs * 1000);
        }
        if ($mode === 'continue') {
            return continueAsNew($flag, $sleepMs, $mode, $generation + 1);
        }

        return [
            'closed' => true,
        ];
    }
}
