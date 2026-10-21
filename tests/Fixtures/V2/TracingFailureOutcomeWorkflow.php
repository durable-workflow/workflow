<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Workflow;

final class TracingFailureOutcomeWorkflow extends Workflow
{
    public function handle(bool $local): array
    {
        $caught = null;
        try {
            $backoff = [1, 2, 5, 10, 15, 30, 60, 120];
            if ($local) {
                self::localActivity(TracingTerminalFailureActivity::class, new LocalActivityOptions(
                    maxAttempts: 1,
                    backoff: $backoff,
                ));
            } else {
                self::activity(TracingTerminalFailureActivity::class, new ActivityOptions(
                    maxAttempts: 1,
                    backoff: $backoff,
                ));
            }
        } catch (RuntimeException $error) {
            $caught = $error->getMessage();
        }
        self::timer(1);

        return [
            'caught' => $caught,
            'value' => [9007199254740993, null, '雪'],
        ];
    }
}
