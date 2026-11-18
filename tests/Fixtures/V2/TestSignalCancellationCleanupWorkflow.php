<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/** Cancellation can reach a signal wait before the wait opens; cleanup runs at the next sequence. */
#[Type('test-signal-cancellation-cleanup-workflow')]
#[Signal('finish')]
final class TestSignalCancellationCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            Workflow::await('finish');
        } finally {
            activity(TestRetryActivity::class, 'cleanup');
        }
    }
}
