<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use function Workflow\V2\signalExternalWorkflow;
use Workflow\V2\Workflow;

/**
 * Signals each target in order, then records a side effect so the next
 * durable sequence after the signals is observable.
 */
#[Type('test-signal-external-sender-workflow')]
final class TestSignalExternalSenderWorkflow extends Workflow
{
    /**
     * @param list<array{workflow_id: string, signal: string, arguments?: list<mixed>, run_id?: string|null}> $targets
     */
    public function handle(array $targets): array
    {
        $results = [];

        foreach ($targets as $target) {
            $results[] = signalExternalWorkflow(
                $target['workflow_id'],
                $target['signal'],
                $target['arguments'] ?? [],
                $target['run_id'] ?? null,
            )->toArray();
        }

        return [
            'results' => $results,
            'after' => sideEffect(static fn (): string => 'after'),
        ];
    }
}
