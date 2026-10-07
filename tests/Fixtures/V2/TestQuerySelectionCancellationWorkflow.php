<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-query-selection-cancellation-workflow')]
final class TestQuerySelectionCancellationWorkflow extends Workflow
{
    private string $stage = 'selecting';

    private mixed $value = null;

    public function handle(): array
    {
        $selected = Workflow::select([
            'first' => static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'first'),
            'second' => static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'second'),
        ]);
        $this->stage = 'requesting-cancellation';
        $selected->handles['second']->cancel();
        $this->value = $selected->handles['second']->await();
        $this->stage = 'waiting-for-finish';
        Workflow::awaitSignal('finish');

        return $this->currentState();
    }

    /**
     * @return array{stage: string, value: mixed}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'value' => $this->value,
        ];
    }
}
