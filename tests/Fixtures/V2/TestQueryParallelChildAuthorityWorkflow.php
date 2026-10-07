<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Workflow;

final class TestQueryParallelChildAuthorityWorkflow extends Workflow
{
    private string $stage = 'waiting-for-children';

    private mixed $value = null;

    private ?string $failure = null;

    public function handle(): array
    {
        try {
            $this->value = Workflow::all([
                static fn () => Workflow::child(TestTimerWorkflow::class, 60),
                static fn () => Workflow::child(TestTimerWorkflow::class, 60),
            ]);
        } catch (Throwable $failure) {
            $this->failure = $failure->getMessage();
        }
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
            'failure' => $this->failure,
        ];
    }
}
