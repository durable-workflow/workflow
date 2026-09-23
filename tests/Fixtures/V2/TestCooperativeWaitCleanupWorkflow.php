<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\await;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

#[Signal('continue')]
final class TestCooperativeWaitCleanupWorkflow extends Workflow
{
    public function handle(string $waitKind): void
    {
        try {
            if ($waitKind === 'signal') {
                await('continue', timeout: 3600);
            } else {
                await(static fn (): bool => false, timeout: 3600, conditionKey: 'never.ready');
            }
        } finally {
            $greeting = activity(TestGreetingActivity::class, 'cleanup');
            upsertMemo([
                'cleanup' => $greeting,
            ]);
        }
    }
}
