<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Workflow;

#[Type('test-service-response-replay-workflow')]
final class TestServiceResponseReplayWorkflow extends Workflow
{
    private mixed $responsePayload = null;

    public function handle(): mixed
    {
        $result = Workflow::serviceOperation(
            'payments',
            'Payments',
            'authorize',
            [
                'amount' => 4200,
                'currency' => 'USD',
            ],
            ServiceOperationOptions::asyncAccepted(),
        );

        $this->responsePayload = $result instanceof ServiceOperationResult
            ? $result->responsePayload
            : null;

        return $this->responsePayload;
    }

    #[QueryMethod]
    public function currentResponsePayload(): mixed
    {
        return $this->responsePayload;
    }
}
