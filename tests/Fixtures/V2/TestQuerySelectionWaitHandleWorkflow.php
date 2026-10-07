<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-query-selection-wait-handle-workflow')]
final class TestQuerySelectionWaitHandleWorkflow extends Workflow
{
    private string $stage = 'selecting';

    private ?string $winner = null;

    private mixed $value = null;

    private ?string $failure = null;

    public function handle(string $kind): array
    {
        $selected = Workflow::select([
            'slow' => static fn () => Workflow::all([
                static fn () => match ($kind) {
                    'timer' => Workflow::timer(60),
                    'signal' => Workflow::await('slow', 60),
                    'condition' => Workflow::await(static fn (): bool => false, null, 'slow.ready'),
                    'condition_timeout' => Workflow::await(static fn (): bool => false, 60, 'slow.ready'),
                    'child' => Workflow::child(TestTimerWorkflow::class, 60),
                },
            ]),
            'fast' => static fn () => Workflow::activity(TestQueryReplayGuardActivity::class, 'fast'),
        ]);
        $this->winner = (string) $selected->key;
        $this->stage = 'awaiting-group';
        try {
            $this->value = $selected->handles['slow']->await();
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
