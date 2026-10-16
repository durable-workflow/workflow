<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Workflow;

final class TracingHandledChildFailureWorkflow extends Workflow
{
    public function handle(): array
    {
        try {
            self::child(TracingFailingChildWorkflow::class);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'deliberate terminal child failure') {
                throw $error;
            }
        }

        return [9007199254740993, null, '雪'];
    }
}
