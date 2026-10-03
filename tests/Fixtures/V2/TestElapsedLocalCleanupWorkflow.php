<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use function Workflow\V2\await;
use function Workflow\V2\localActivity;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

#[Signal('continue')]
final class TestElapsedLocalCleanupWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            await('continue', timeout: 3600);
        } finally {
            upsertMemo([
                'cleanup' => localActivity(TestElapsedLocalCleanupActivity::class),
            ]);
        }
    }
}
