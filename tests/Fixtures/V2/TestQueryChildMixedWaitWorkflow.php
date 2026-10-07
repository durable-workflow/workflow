<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Workflow;

final class TestQueryChildMixedWaitWorkflow extends Workflow
{
    private string $stage = 'waiting-for-mixed-group';

    private mixed $value = null;

    public function handle(): array
    {
        $this->value = Workflow::all([
            static fn () => Workflow::child(TestTimerWorkflow::class, 60),
            static fn () => Workflow::timer(60),
            static fn () => Workflow::await('approval', 60),
            static fn () => Workflow::await(static fn (): bool => false, 60, 'approval.ready'),
        ]);
        $this->stage = 'waiting-for-finish';
        Workflow::awaitSignal('finish');

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'value' => $this->value,
        ];
    }
}
