<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestRepeatedPatchClockWorkflow extends Workflow
{
    private array $clocks = [];

    public function handle(): array
    {
        self::patched('same');
        $this->clocks[] = self::now()->toIso8601String();
        self::patched('same');
        $this->clocks[] = self::now()->toIso8601String();
        self::activity(TestQueryReplayGuardActivity::class);

        return $this->clocks;
    }

    public function currentState(): array
    {
        return $this->clocks;
    }
}
