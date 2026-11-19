<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/** No effects or new obligations: only the authorized, verified original generation may become cancelled. */
#[Type('example.configured.cancellation.v1')]
final class Cancellation extends Workflow
{
    public function handle(string $subject, string $rootRun, string $requestKey): array
    {
        $initialization = self::activity(
            BoundaryActivity::class,
            'initialize-finalizer',
            compact('subject', 'rootRun', 'requestKey')
        );
        return (new MachineExecution($this, $initialization, $initialization['principal']))->transition(
            'cancel'
        )->toArray();
    }
}
