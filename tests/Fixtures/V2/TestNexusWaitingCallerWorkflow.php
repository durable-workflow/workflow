<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Workflow;

#[Type('test-nexus-waiting-caller')]
final class TestNexusWaitingCallerWorkflow extends Workflow
{
    public function handle(string $name): mixed
    {
        $result = Workflow::serviceOperation('php-service', 'greeter', 'greet', [$name], new ServiceOperationOptions(
            targetNamespace: 'service',
            modeOverride: 'sync',
            waitFor: 'completed',
        ));

        return $result instanceof ServiceOperationResult ? $result->responsePayload : null;
    }
}
