<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Workflow;

#[Type('test-nexus-deadline-caller')]
final class TestNexusDeadlineCallerWorkflow extends Workflow
{
    public function handle(string $name): mixed
    {
        try {
            $result = Workflow::serviceOperation(
                'php-service',
                'greeter',
                'greet',
                [$name],
                new ServiceOperationOptions(targetNamespace: 'service', modeOverride: 'sync', waitFor: 'completed')
            );

            return $result instanceof ServiceOperationResult ? $result->responsePayload : null;
        } catch (RuntimeException $exception) {
            return [
                'failure_message' => $exception->getMessage(),
            ];
        }
    }
}
