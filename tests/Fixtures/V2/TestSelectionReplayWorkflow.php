<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-selection-replay-workflow')]
final class TestSelectionReplayWorkflow extends Workflow
{
    /**
     * @return array{winner: int|string, winner_identity: string, winner_value: mixed, slow: mixed}
     */
    public function handle(): array
    {
        $selected = Workflow::select([
            'slow' => static fn () => Workflow::activity('slow-activity'),
            'fast' => static fn () => Workflow::activity('fast-activity'),
        ]);

        return [
            'winner' => $selected->key,
            'winner_identity' => $selected->identity,
            'winner_value' => $selected->result(),
            'slow' => $selected->handles['slow']->await(),
        ];
    }
}
