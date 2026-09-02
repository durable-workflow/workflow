<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-selection-cancel-loser-workflow')]
final class TestSelectionCancelLoserWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @return array<string, mixed>
     */
    public function handle(string $name): array
    {
        $selected = Workflow::select([
            'work' => static fn () => Workflow::activity(TestGreetingActivity::class, $name),
            'deadline' => static fn () => Workflow::timer(0),
        ]);
        $selected->handles['work']->cancel();
        $this->stage = 'completed';

        return [
            'winner' => $selected->key,
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
