<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\signalExternalWorkflow;
use Workflow\V2\Workflow;

#[Type('test-signal-external-ping-workflow')]
#[Signal('ping')]
final class TestSignalExternalPingWorkflow extends Workflow
{
    public function handle(
        string $peer,
        string $signal,
        string $flag,
        int $sleepMs,
        bool $awaitPing,
        ?string $runId = null
    ): array {
        if ($flag !== '' && is_file($flag)) {
            usleep($sleepMs * 1000);
        }
        $sent = signalExternalWorkflow($peer, $signal, [$this->workflowId()], $runId)->toArray();
        $got = $awaitPing ? await('ping') : null;

        return [
            'sent' => $sent,
            'got' => $got,
        ];
    }
}
