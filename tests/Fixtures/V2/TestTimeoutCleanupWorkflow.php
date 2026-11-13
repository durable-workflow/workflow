<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\VersionRangeMismatchException;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

/**
 * Waits past its work deadline, then handles the timeout cleanup interruption
 * (#863): returns, throws, or keeps waiting until the hard deadline.
 */
#[Type('test-timeout-cleanup-workflow')]
final class TestTimeoutCleanupWorkflow extends Workflow
{
    public ?string $cause = null;

    public function handle(string $mode): string
    {
        try {
            timer(3600);

            return 'not-interrupted';
        } catch (WorkflowTimeoutCleanupRequested $interruption) {
            $this->cause = $interruption->cancellation?->cause;

            return match ($mode) {
                'fail' => throw new \RuntimeException('Compensation failed.'),
                'rethrow' => throw $interruption,
                'version-mismatch' => throw new VersionRangeMismatchException('cleanup', 1, 2, 3),
                'history-mismatch' => throw new HistoryEventShapeMismatchException(1, 'activity', ['TimerStarted']),
                'unresolved-failure' => throw UnresolvedWorkflowFailureException::unresolved([
                    'class' => 'UnavailableCleanupFailure',
                ]),
                'expire' => (string) timer(3600),
                default => 'cleaned-up',
            };
        }
    }
}
