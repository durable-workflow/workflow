<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestQueryServiceOperationWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?array $result = null;

    private ?array $failure = null;

    public function handle(string $mode): array
    {
        $this->stage = 'waiting-for-service';

        try {
            $result = Workflow::serviceOperation(
                'payments',
                'Payments',
                'authorize',
                [
                    'amount' => 4200,
                    'currency' => 'USD',
                ],
                $mode === 'async'
                    ? ServiceOperationOptions::asyncAccepted()
                    : ServiceOperationOptions::syncCompleted(),
            );
            $this->result = $result instanceof ServiceOperationResult ? $result->toArray() : null;
        } catch (Throwable $failure) {
            $this->failure = [
                'class' => $failure::class,
                'original_class' => $failure instanceof RestoredWorkflowException
                    ? $failure->originalExceptionClass()
                    : $failure::class,
                'message' => $failure->getMessage(),
                'code' => $failure->getCode(),
                'type' => $failure instanceof RestoredWorkflowException
                    ? ($failure->failurePayload()['type'] ?? null)
                    : null,
            ];
        }

        $this->stage = 'waiting-for-timer';
        timer(60);
        $this->stage = 'completed';

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'result' => $this->result,
            'failure' => $this->failure,
        ];
    }
}
