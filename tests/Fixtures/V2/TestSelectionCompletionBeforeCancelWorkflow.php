<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-selection-completion-before-cancel-workflow')]
final class TestSelectionCompletionBeforeCancelWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @return array{winner: int|string, loser: mixed}
     */
    public function handle(): array
    {
        $selected = Workflow::select([
            'first' => static fn () => Workflow::activity(TestGreetingActivity::class, 'First'),
            'second' => static fn () => Workflow::activity(TestGreetingActivity::class, 'Second'),
        ]);
        $selected->handles['second']->cancel();
        $this->stage = 'cancel-committed';
        $loser = $selected->handles['second']->await();
        $this->stage = 'completed';

        return [
            'winner' => $selected->key,
            'loser' => $loser,
        ];
    }

    /**
     * @return array{stage: string}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
        ];
    }
}
