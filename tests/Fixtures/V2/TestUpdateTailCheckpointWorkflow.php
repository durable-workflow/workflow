<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;

final class TestUpdateTailCheckpointWorkflow extends Workflow
{
    private int $updates = 0;

    public function handle(): array
    {
        getVersion('before', 1, 1);
        return [
            'version' => getVersion('after', 1, 1),
            'updates' => $this->updates,
        ];
    }

    #[UpdateMethod]
    public function set(): int
    {
        return ++$this->updates;
    }
}
