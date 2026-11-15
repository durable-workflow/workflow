<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.snapshot.v1')]
final class SnapshotActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(AssessmentStore::class)->snapshot($this->run, $this->activityId(), $request);
    }
}
