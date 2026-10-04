<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\cancellationShield;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestChildPolicyCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            timer(3600);
        } finally {
            cancellationShield(static function (): void {
                timer(2);
                upsertMemo([
                    'child_cleanup' => 'complete',
                ]);
            });
        }
    }
}
