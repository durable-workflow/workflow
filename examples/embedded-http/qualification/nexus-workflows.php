<?php

declare(strict_types=1);

namespace App\Workflows;

use RuntimeException;
use Throwable;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Workflow;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class PublishedNexusFailure extends RuntimeException
{
}

final class PublishedNexusCaller extends Workflow
{
    public function handle(string $scenario, array $payload): array
    {
        try {
            $result = Workflow::serviceOperation('embedded-preview', 'greeter', 'run', [$scenario, $payload], new ServiceOperationOptions(
                targetNamespace: 'shared',
                modeOverride: 'sync',
                waitFor: 'completed',
            ));

            return ['status' => $result->status, 'call_id' => $result->serviceCallId, 'value' => $result->responsePayload];
        } catch (WorkflowCancellationRequestedException $cancel) {
            upsertMemo(['nexus_cleanup' => $cancel->cancellation?->toArray()]);
            timer(1);
            throw $cancel;
        } catch (Throwable $failure) {
            return ['error_class' => $failure::class, 'error_message' => $failure->getMessage()];
        }
    }
}

final class PublishedNexusTarget extends Workflow
{
    public function handle(string $scenario, array $payload): array
    {
        if ($scenario === 'failed') {
            throw new PublishedNexusFailure('embedded service failure');
        }

        try {
            if (in_array($scenario, ['target_cancel', 'caller_cancel'], true)) {
                timer(3600);
            }

            return $payload;
        } catch (WorkflowCancellationRequestedException $cancel) {
            upsertMemo(['nexus_cleanup' => $cancel->cancellation?->toArray()]);
            timer(1);
            throw $cancel;
        }
    }
}
