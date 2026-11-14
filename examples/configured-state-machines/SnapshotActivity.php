<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.configured.snapshot.v1')]
final class SnapshotActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(MachineStore::class)->snapshot($this->run, $this->activityId(), $request);
    }
}
