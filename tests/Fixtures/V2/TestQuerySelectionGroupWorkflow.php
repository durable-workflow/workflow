<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-query-selection-group-workflow')]
final class TestQuerySelectionGroupWorkflow extends Workflow
{
    private string $stage = 'selecting';

    private ?string $winner = null;

    private mixed $value = null;

    private ?string $failure = null;

    public function handle(): array
    {
        $selected = Workflow::select([
            'group' => static fn () => Workflow::all([
                static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'first'),
                static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'second'),
            ]),
            'fast' => static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'fast'),
        ]);
        $this->winner = (string) $selected->key;
        $this->stage = 'awaiting-group';
        try {
            $this->value = $selected->handles['group']->await();
        } catch (Throwable $exception) {
            $this->failure = $exception->getMessage();
        }
        $this->stage = 'waiting-for-finish';
        Workflow::awaitSignal('finish');

        return $this->currentState();
    }

    /**
     * @return array{stage: string, winner: ?string, value: mixed, failure: ?string}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'winner' => $this->winner,
            'value' => $this->value,
            'failure' => $this->failure,
        ];
    }
}
