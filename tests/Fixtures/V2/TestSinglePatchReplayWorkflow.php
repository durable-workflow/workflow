<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestSinglePatchReplayWorkflow extends Workflow
{
    private bool $patched = false;

    private ?string $value = null;

    public function handle(): array
    {
        $this->patched = self::patched('same');
        $this->value = self::activity(TestQueryReplayGuardActivity::class);

        return [$this->patched, $this->value];
    }

    public function currentState(): array
    {
        return [$this->patched, $this->value];
    }
}
