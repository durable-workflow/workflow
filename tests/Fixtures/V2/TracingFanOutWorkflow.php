<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TracingFanOutWorkflow extends Workflow
{
    public function handle(): array
    {
        $local = self::localActivity(TracingProcessActivity::class, false);
        $parallel = self::all([
            static fn (): mixed => self::activity(TracingProcessActivity::class, false),
            static fn (): mixed => self::activity(TracingProcessActivity::class, new ActivityOptions(
                maxAttempts: 2,
                backoff: [1],
                startToCloseTimeout: 10,
                scheduleToCloseTimeout: 30,
            ), true),
            static fn (): mixed => self::timer(1),
        ]);

        return [
            'ordinary' => $parallel[0],
            'retry' => $parallel[1],
            'local' => $local,
            'timer' => $parallel[2],
        ];
    }
}
