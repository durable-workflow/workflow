<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.configured.commit.v1')]
final class CommitActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(MachineStore::class)->commit($this->run, $request);
    }
}
