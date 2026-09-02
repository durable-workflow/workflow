<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-selection-workflow')]
final class TestSelectionWorkflow extends Workflow
{
    private string $stage = 'booting';

    /**
     * @return array<string, mixed>
     */
    public function handle(string $name, int $deadlineSeconds): array
    {
        $this->stage = 'selecting';
        $selected = Workflow::select([
            'work' => static fn () => Workflow::activity(TestGreetingActivity::class, $name),
            'deadline' => static fn () => Workflow::timer($deadlineSeconds),
        ]);
        $this->stage = 'selected-' . (string) $selected->key;

        return [
            'stage' => $this->stage,
            'key' => $selected->key,
            'kind' => $selected->kind,
            'identity' => $selected->identity,
            'result' => $selected->result(),
            'remaining' => array_keys($selected->remaining()),
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
