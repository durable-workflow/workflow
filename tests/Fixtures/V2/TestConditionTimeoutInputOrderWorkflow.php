<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\select;
use Workflow\V2\Workflow;

/**
 * A timeout-backed approval wait followed by a second wait, so a run stays
 * in flight after the first wait resolves and later tasks replay its outcome.
 */
#[Type('test-condition-timeout-input-order-workflow')]
final class TestConditionTimeoutInputOrderWorkflow extends Workflow
{
    private bool $approved = false;

    private bool $finished = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(string $mode = 'standalone'): array
    {
        if ($mode === 'select') {
            $selected = select([
                'approval' => fn (): mixed => await(fn (): bool => $this->approved, 5, 'approval.ready'),
            ]);
            $approved = $selected->value;
        } else {
            $approved = await(fn (): bool => $this->approved, timeout: 5, conditionKey: 'approval.ready');
        }

        $this->events[] = $approved ? 'condition-satisfied' : 'condition-timed-out';

        await(fn (): bool => $this->finished, conditionKey: 'finish.ready');

        return [
            'approved' => $approved,
            'approved_state' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'approved' => $this->approved,
            'finished' => $this->finished,
            'events' => $this->events,
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;
        $this->events[] = $approved ? 'approve' : 'unapprove';

        return $this->currentState();
    }

    #[UpdateMethod]
    public function finish(): array
    {
        $this->finished = true;

        return $this->currentState();
    }
}
