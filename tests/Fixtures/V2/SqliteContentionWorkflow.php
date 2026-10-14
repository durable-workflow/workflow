<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

#[Signal('finish', ['value'])]
final class SqliteContentionWorkflow extends Workflow
{
    public function handle(bool $waitForSignal, bool $waitForTimer = false): array
    {
        $result = self::activity(SqliteContentionActivity::class);
        if ($waitForTimer) {
            self::timer(1);
        }
        if ($waitForSignal) {
            self::awaitSignal('finish');
        }

        return $result;
    }
}
