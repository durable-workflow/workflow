<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Workflow;

final class TestLegacyPatchReplayWorkflow extends Workflow
{
    private bool $patched = false;

    private ?string $value = null;

    private string $stage = 'starting';

    public function handle(string $patchBefore = 'activity'): array
    {
        if ($patchBefore === 'activity') {
            $this->patched = Workflow::patched('legacy-upgrade');
        }

        $this->value = Workflow::activity(TestQueryReplayGuardActivity::class);

        if ($patchBefore === 'timer') {
            $this->patched = Workflow::patched('legacy-upgrade');
        }

        $this->stage = 'waiting-for-timer';
        Workflow::timer(60);
        $this->stage = 'completed';

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'patched' => $this->patched,
            'value' => $this->value,
            'stage' => $this->stage,
        ];
    }
}
